﻿

namespace TU
{
#include "diff.hpp"
#include "zipall.h"
#include "rest.h"



	inline const wchar_t* OldSuffix = L".{1DAE2EA5-922C-4049-AD37-0AA3E0EE7DC0}.old";
	using namespace std;
	struct TUPATCH
	{
		int num = 0;
		unsigned long long from;
		unsigned long long len;
		vector<char> data;
	};

	struct TUFILE
	{
		wstring Local;
		string Remote;
		vector<char> RSig;
		vector<char> LSig;
		vector<char> Diff;
		vector<char> LHash;
		vector<char> RHash;
		vector<TUPATCH> Patches;

		TUFILE(const wchar_t* l, const char* r)
		{
			Local = l;
			Remote = r;
		}
	};

	struct ENDPOINT
	{
		wstring host;
		bool SSL = false;
		unsigned short Port = 0;
		DWORD flg = 0;
		wstring user;
		wstring pass;
		wstring path;
		wstring uploadpass;
	};

	struct PTR
	{
		const char* p = 0;
		size_t sz = 0;

		PTR()
		{

		}

		PTR(const vector<char>& d)
		{
			p = d.data();
			sz = d.size();
		}

		explicit operator bool()
		{
			if (p && sz)
				return true;
			return false;
		}
	};

	inline wstring u(const char* t)
	{
		vector<wchar_t> x(strlen(t) + 100);
		MultiByteToWideChar(CP_UTF8, 0, t,(int) strlen(t), x.data(),(int) x.size());
		return x.data();
	}

	inline string a(const wchar_t* t)
	{
		vector<char> x(wcslen(t)*2 + 100);
		WideCharToMultiByte(CP_UTF8, 0, t, (int)wcslen(t), x.data(), (int)x.size(),0,0);
		return x.data();
	}

	class VisibleUpdate
	{
	public:

		TASKDIALOGCONFIG tc = { 0 };
		HWND hTask = 0;
		bool Cancelled = false;
		shared_ptr<std::thread> t;

		void Thr()
		{
			CoInitializeEx(0, COINIT_APARTMENTTHREADED);
			int p1 = 0;
			int p2 = 0;
			BOOL p3 = 0;
			TaskDialogIndirect(&tc, &p1, &p2, &p3);
		}

		VisibleUpdate(HWND hParent = 0,HICON hIc = LoadIcon(0,IDI_INFORMATION))
		{
			tc.cbSize = sizeof(tc);
			tc.hwndParent = hParent;
			tc.pszWindowTitle = L"Update Process";
			tc.hMainIcon = hIc;
			tc.pszMainInstruction = L"Update Process";
			tc.pszContent = L"Downloading updates...";
			tc.dwCommonButtons = TDCBF_CANCEL_BUTTON;
			tc.dwFlags = TDF_SHOW_PROGRESS_BAR | TDF_ALLOW_DIALOG_CANCELLATION | TDF_USE_HICON_MAIN;
			tc.pfCallback = [](HWND hwnd, UINT uNotification, WPARAM wParam, LPARAM lParam, LONG_PTR dwRefData) -> HRESULT
			{
				VisibleUpdate* tu = (VisibleUpdate*)dwRefData;
				if (uNotification == TDN_CREATED)
				{
					tu->hTask = hwnd;
					SendMessage(hwnd, TDM_SET_PROGRESS_BAR_RANGE, 0, MAKELPARAM(0, 100));
					return S_OK;
				}
				if (uNotification == TDN_BUTTON_CLICKED)
				{
					tu->Cancelled = true;
					return S_OK;
				}
				return S_OK;
			};

			tc.lpCallbackData = (LONG_PTR)this;

			t = make_shared<thread>(&VisibleUpdate::Thr, this);
			for (int i = 0; i < 10; i++)
			{
				Sleep(100);
				if (hTask)
					break;
			}
		}

		~VisibleUpdate()
		{
			if (IsWindow(hTask))
				SendMessage(hTask, WM_CLOSE, 0, 0);
			if (t)
				t->join();
			t = 0;
			hTask = 0;
		}

		HRESULT Update(unsigned long long sent, unsigned long long tot)
		{
//			Sleep(1000);
			if (Cancelled)
				return E_FAIL;
			if (!tot)
				return S_OK;
			sent *= 100;
			sent /= tot;
			SendMessage(hTask, TDM_SET_PROGRESS_BAR_POS,(WPARAM) sent,0);
			return S_OK;
		}
	};


	class HASH
	{
		BCRYPT_ALG_HANDLE h;
		BCRYPT_HASH_HANDLE ha;
	public:

		HASH(const wchar_t* alg = BCRYPT_SHA256_ALGORITHM)
		{
			BCryptOpenAlgorithmProvider(&h, alg, 0, 0);
			if (h)
				BCryptCreateHash(h, &ha, 0, 0, 0, 0, 0);
		}

		bool hash(const char* d, DWORD sz)
		{
			if (!ha)
				return false;
			auto nt = BCryptHashData(ha, (UCHAR*)d, sz, 0);
			return (nt == 0) ? true : false;
		}

		bool get(std::vector<char>& b)
		{
			DWORD hl;
			ULONG rs;
			if (!ha)
				return false;
			auto nt = BCryptGetProperty(ha, BCRYPT_HASH_LENGTH, (PUCHAR)& hl, sizeof(DWORD), &rs, 0);
			if (nt != 0)
				return false;
			b.resize(hl);
			nt = BCryptFinishHash(ha, (BYTE*)b.data(), hl, 0);
			if (nt != 0)
				return false;
			return true;
		}

		~HASH()
		{
			if (ha)
				BCryptDestroyHash(ha);
			ha = 0;
			if (h)
				BCryptCloseAlgorithmProvider(h, 0);
			h = 0;
		}
	};

	class TU
	{
	private:
		vector<TUFILE> files;
		RESTAPI::REST rest;
		string ProjectGuid;
		ENDPOINT endpoint;
		HICON hIC;

		wstring TempFile()
		{
			wchar_t fte[1000] = { 0 };
			GetTempPath(1000, fte);
			wchar_t ft[1000] = { 0 };
			GetTempFileName(fte, L"tmp", 0, ft);
			DeleteFile(ft);
			return ft;
		}
		string TempFileA()
		{
			char fte[1000] = { 0 };
			GetTempPathA(1000, fte);
			char ft[1000] = { 0 };
			GetTempFileNameA(fte, "tmp", 0, ft);
			DeleteFileA(ft);
			return ft;
		}

		wstring Self()
		{
			wchar_t a[1000] = { 0 };
			GetModuleFileName(GetModuleHandle(0), a, 1000);
			return a;
		}


	public:

		vector<TUFILE>& GetFiles() { return files; }
		TU(const char* prjg,const wchar_t* host,const wchar_t* path,bool SSL = false,unsigned short Port = 0,DWORD flg = 0,const wchar_t* un = 0,const wchar_t* pwd = 0,const wchar_t* uploadpwd = 0)
		{
			ProjectGuid = prjg;
			endpoint.host = host;
			endpoint.path = path;
			endpoint.SSL = SSL;
			endpoint.Port = Port;
			endpoint.flg = flg;
			if (un)
				endpoint.user = un;
			if (pwd)
				endpoint.pass = pwd;
			if (uploadpwd)
				endpoint.uploadpass = uploadpwd;
		}

		void AddFiles(vector<tuple<wstring,string>>& nfiles)
		{
			for (auto& f : nfiles)
			{
				TUFILE tuf(get<0>(f).c_str(),get<1>(f).c_str());
				files.push_back(tuf);
			}
		}

		void AddSelf(const char* r)
		{
			auto a = Self();
			vector<tuple<wstring, string>> tux;
			tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(a), string(r)));
			AddFiles({ tux });
		}

		void SetIcon(HICON h)
		{
			hIC = h;
		}

		void OneOff(const char* r,bool RunNow = false,HICON hIc = LoadIcon(0,IDI_INFORMATION),bool NoDiff = false)
		{
			AddSelf(r);
			Off(RunNow, hIc, NoDiff);
		}

		HRESULT Off(bool RunNow = false, HICON hIc = LoadIcon(0, IDI_INFORMATION), bool NoDiff = false,bool CheckOnly = false)
		{
			SetIcon(hIc);
			auto hr = E_FAIL;
			if (!NoDiff)
				hr = CheckWithSigs();
			if (hr == S_OK)
				return hr;
			if (CheckOnly)
				return hr;
			auto hre = E_FAIL;
			if (hr == S_FALSE)
				hre = DownloadDiff();
			else
			{
				// Plain
				hr = Check();
				if (hr == S_FALSE)
					hre = DownloadFull();
			}

			if (RunNow && SUCCEEDED(hre))
			{
				auto a = Self();
				ShellExecute(0, L"open", a.c_str(), 0, 0, SW_SHOWNORMAL);
				ExitProcess(0);
			}
			return hr;
		}



		HRESULT CreateSignatureFor(const wchar_t* fil,vector<char>& sig)
		{
			if (!fil)
				return E_INVALIDARG;
			HANDLE hF = CreateFile(fil, GENERIC_READ, FILE_SHARE_READ, 0, OPEN_EXISTING, 0, 0);
			if (hF == INVALID_HANDLE_VALUE)
				return E_FAIL;
			std::thread t([&]()
				{
					CoInitializeEx(0, COINIT_MULTITHREADED);
					DIFFLIB::DIFF d;

					DIFFLIB::MemoryDiffWriter dw;
					DIFFLIB::FileRdcFileReader f(hF);
					d.GenerateSignature(&f, dw);
					sig = dw.p();
				});

			t.join();
			CloseHandle(hF);
			if (sig.empty())
				return E_FAIL;
			return S_OK;
		}

		HRESULT LoadFileA(const char* fil, vector<char>& sig)
		{
			if (!fil)
				return E_INVALIDARG;
			HANDLE hF = CreateFileA(fil, GENERIC_READ, FILE_SHARE_READ, 0, OPEN_EXISTING, 0, 0);
			if (hF == INVALID_HANDLE_VALUE)
				return E_FAIL;
			LARGE_INTEGER li = { 0 };
			GetFileSizeEx(hF, &li);
			sig.resize((size_t)li.QuadPart);
			DWORD A = 0;
			ReadFile(hF, sig.data(), (DWORD)sig.size(), &A, 0);
			CloseHandle(hF);
			if (A != sig.size())
				return E_FAIL;
			return S_OK;
		}

		HRESULT LoadFile(const wchar_t* fil, vector<char>& sig)
		{
			if (!fil)
				return E_INVALIDARG;
			HANDLE hF = CreateFile(fil, GENERIC_READ, FILE_SHARE_READ, 0, OPEN_EXISTING, 0, 0);
			if (hF == INVALID_HANDLE_VALUE)
				return E_FAIL;
			LARGE_INTEGER li = { 0 };
			GetFileSizeEx(hF, &li);
			sig.resize((size_t)li.QuadPart);
			DWORD A = 0;
			ReadFile(hF, sig.data(), (DWORD)sig.size(), &A, 0);
			CloseHandle(hF);
			if (A != sig.size())
				return E_FAIL;
			return S_OK;
		}

		
		HRESULT PutFile(const wchar_t* f, vector<char> & d)
		{
			HANDLE hX = CreateFile(f, GENERIC_WRITE, 0, 0, CREATE_NEW, 0, 0);
			if (hX == INVALID_HANDLE_VALUE)
				return E_FAIL;
			DWORD A = 0;
			WriteFile(hX, d.data(), (DWORD)d.size(), &A, 0);
			CloseHandle(hX);
			if (A != d.size())
				return E_FAIL;
			return S_OK;
		}



		HRESULT DownloadFull(std::function<HRESULT(unsigned long long, unsigned long long, void*)> func = nullptr, void* lp = 0)
		{
			return foo(1,func,lp);
		}

		HRESULT DownloadDiff(std::function<HRESULT(unsigned long long, unsigned long long, void*)> func = nullptr, void* lp = 0)
		{
			int PatchNum = 1;
			// Process the sigs
			for (auto& f : files)
			{
				if (f.RSig.empty())
					continue;
				HANDLE hX = CreateFile(f.Local.c_str(), GENERIC_READ, FILE_SHARE_READ, 0, OPEN_EXISTING, 0, 0);
				if (hX == INVALID_HANDLE_VALUE)
					continue;

				std::thread t([&]()
					{
						CoInitializeEx(0, COINIT_MULTITHREADED);
						DIFFLIB::DIFF diff;
						CComPtr<DIFFLIB::FileRdcFileReader> fr;
						fr.Attach(new DIFFLIB::FileRdcFileReader(hX));
						DIFFLIB::MemoryDiffWriter LocalSig;
						diff.GenerateSignature(fr, LocalSig);
						f.LSig = LocalSig.p();


						CComPtr<IRdcFileReader> fr1 = LocalSig.GetReader();
						CComPtr<DIFFLIB::MemoryRdcFileReader> fr2;
						fr2.Attach(new DIFFLIB::MemoryRdcFileReader(f.RSig.data(), f.RSig.size()));

						// diff generator
						DIFFLIB::MemoryDiffWriter DiffResult;
						diff.GenerateDiff(fr1, fr2, 0, DiffResult);

						f.Diff = DiffResult.p();
					});
				t.join();
				CloseHandle(hX);
				if (f.Diff.empty())
					return E_FAIL;

				char* p0 = f.Diff.data();
				size_t NumRdcNeed = f.Diff.size() / sizeof(RdcNeed);
				for (size_t n = 0; n < NumRdcNeed; n++)
				{
					RdcNeed* rn = (RdcNeed*)p0;
					if (rn->m_BlockType == RDCNEED_SOURCE)
					{
						// We need to download this

						TUPATCH patch;
						patch.num = PatchNum++;
						patch.from = rn->m_FileOffset;
						patch.len = rn->m_BlockLength;
						f.Patches.push_back(patch);
					}
					p0 += sizeof(RdcNeed);
				}
			}
			return foo(3, func, lp);
		}

		void DeleteOlds()
		{
			for (auto& f : files)
			{
				wstring fx = f.Local + OldSuffix;
				DeleteFile(fx.c_str());
			}
		}

		HRESULT Check()
		{
			DeleteOlds();
			return foo(0);
		}

		HRESULT CheckWithSigs(std::function<HRESULT(unsigned long long, unsigned long long, void*)> func = nullptr, void* lp = 0)
		{
			DeleteOlds();
			return foo(2,func,lp);
		}

		HRESULT foo(int mode = 0, std::function<HRESULT(unsigned long long, unsigned long long, void*)> func = nullptr, void* lp = 0)
		{
			string tmp = TempFileA();

			ZIPUTILS::ZIP z(tmp.c_str());
			vector<char> zipdata;
			vector<char> zipdataHashesFor3;

			for (auto& m : files)
			{
				vector<char> tt;
				HASH h;
				if (FAILED(LoadFile(m.Local.c_str(), tt)))
					h.hash("", 0);
				else
					h.hash(tt.data(), (DWORD)tt.size());

				vector<char> ht;
				h.get(ht);
				PTR item(ht);

				string f1 = "hash-" + m.Remote;
				if (FAILED(z.PutFile(f1.c_str(), item.p, (unsigned long)item.sz)))
					return E_FAIL;
			}
			LoadFileA(tmp.c_str(), zipdata);
			DeleteFileA(tmp.c_str());
			if (zipdata.empty())
				return E_FAIL;

			if (mode == 3)
			{
				zipdataHashesFor3 = zipdata;
				zipdata.clear();

				// Put the patching information
				// ID,from,len|
				string d;
				for (auto& f : files)
				{
					if (f.RSig.empty())
						continue;

					if (f.Patches.empty())
					{
						// Put a full patch
						char a[1000] = { 0 };
						sprintf_s(a, 1000, "%s,F,0,0", f.Remote.c_str());
						if (d != "")
							d += "|";
						d += a;
					}
					else
					for (auto& p : f.Patches)
					{
						char a[1000] = { 0 };
						sprintf_s(a, 1000, "%s,%u,%llu,%llu",f.Remote.c_str(),p.num,p.from,p.len);
						if (d != "")
							d += "|";
						d += a;
					}
				}
				zipdata.resize(d.size());
				memcpy(zipdata.data(), d.data(), d.size());

			}



			// Upload
			if (FAILED(rest.Connect(endpoint.host.c_str(), endpoint.SSL, endpoint.Port,endpoint.flg, endpoint.user.c_str(), endpoint.pass.c_str())))
				return E_FAIL;

			if (mode == 1 || mode == 3)
			{
				// Download also the hash list
				wstring hdr0 = L"X-Project: " + u(ProjectGuid.c_str());
				wstring hdr1 = L"X-Function: hashes";
				wstring hdr2 = L"Content-Type: application/octet-stream";
				wchar_t cl[1000];
				PTR p(mode == 3 ? zipdataHashesFor3 : zipdata);
				swprintf_s(cl, 1000, L"Content-Length: %zu", p.sz);
				wstring hdr3 = cl;
				auto hIX = rest.RequestWithBuffer(endpoint.path.c_str(), L"POST", { hdr0,hdr1,hdr2,hdr3 }, p.p, p.sz, 0, 0, true, 0);
				vector<char> r;
				rest.ReadToMemory(hIX, r);

				ZIPUTILS::ZIP z(r.data(), r.size());
				vector<ZIPUTILS::mz_zip_archive_file_stat> l;
				if (FAILED(z.EnumFiles(l)))
					return E_FAIL;
				for (auto& ll : l)
				{
					vector<char> d;
					if (FAILED(z.Extract(ll.m_filename, d)))
						return E_FAIL;
					if (d.empty())
						return E_FAIL;

					// Put the hash
					for (auto& f : files)
					{
						if (f.Remote == ll.m_filename)
						{
							f.RHash = d;
							break;
						}
					}
				}
			}

			wstring hdr0 = L"X-Project: " + u(ProjectGuid.c_str());
			wstring hdr1 = L"X-Function: check";
			if (mode == 1)
				hdr1 = L"X-Function: download";
			if (mode == 2)
				hdr1 = L"X-Function: checkandsig";
			if (mode == 3)
				hdr1 = L"X-Function: patch2";
			wstring hdr2 = L"Content-Type: application/octet-stream";
			wchar_t cl[1000];
			swprintf_s(cl, 1000, L"Content-Length: %zu", zipdata.size());
			wstring hdr3 = cl;
			auto hIX = rest.RequestWithBuffer(endpoint.path.c_str(), L"POST", { hdr0,hdr1,hdr2,hdr3 }, zipdata.data(), zipdata.size(), 0, 0, true, 0);
			vector<char> r;
			shared_ptr<VisibleUpdate> vu;
			if (!func && (mode == 3 || mode == 1))
				vu = make_shared<VisibleUpdate>(nullptr,forward<HICON>(hIC));
			rest.ReadToMemory(hIX, r, [&](unsigned long long sent, unsigned long long tot, void* lp) -> HRESULT
				{
					if (func)
						return func(sent, tot, lp);
					if (vu)
						return vu->Update(sent, tot);
					return S_OK;
				},lp
			);
			vu = nullptr;

			if (mode == 3)
			{
				// Patch zip
				char* rz = r.data();
				ZIPUTILS::ZIP z(rz, r.size());
				vector<ZIPUTILS::mz_zip_archive_file_stat> l;
				if (FAILED(z.EnumFiles(l)))
					return E_FAIL;
				for (auto& ll : l)
				{
					vector<char> d;
					if (FAILED(z.Extract(ll.m_filename, d)))
						return E_FAIL;

					// Check if full
					bool WasFull = false;
					for (auto& f : files)
					{
						if (f.Remote == string(ll.m_filename))
						{
							WasFull = true;
							TUPATCH tup;
							tup.num = 0;
							tup.data = d;
							tup.from = 0;
							tup.len = d.size();
							f.Patches.push_back(tup);
						}
					}
					if (WasFull)
						continue;

					int numx = atoi(ll.m_filename);

					// Find it
					for (auto& f : files)
					{
						for (auto& p : f.Patches)
						{
							if (p.num == numx)
								p.data = d;
						}
					}
				}


				// Reconstruct files now
				for (auto& f : files)
				{
					if (f.Patches.empty())
						continue;

					vector<char> Here;
					vector<char> There;
					LoadFile(f.Local.c_str(), Here);

					if (Here.empty())
					{
						// Use Full
						There = f.Patches[0].data;
					}

					char* p0 = f.Diff.data();
					size_t NumRdcNeed = f.Diff.size() / sizeof(RdcNeed);
					for (size_t n = 0; n < NumRdcNeed; n++)
					{
						RdcNeed* rn = (RdcNeed*)p0;
						if (rn->m_BlockType == RDCNEED_SEED)
						{
							auto e = There.size();
							There.resize(There.size() + (size_t)rn->m_BlockLength);
							memcpy(There.data() + e, Here.data() + (size_t)rn->m_FileOffset, (size_t)rn->m_BlockLength);
						}
						if (rn->m_BlockType == RDCNEED_SOURCE)
						{
							// We find the patch...
							signed long long ip = -1;
							for (unsigned int y = 0; y < f.Patches.size(); y++)
							{
								auto& pa = f.Patches[y];
								if (pa.from == rn->m_FileOffset && pa.len == rn->m_BlockLength && pa.data.size() == pa.len)
								{
									ip = y;
									break;
								}
							}
							if (ip == -1)
								return E_FAIL;
							auto& pp = f.Patches[(size_t)ip];
							auto e = There.size();
							There.resize(There.size() + (size_t)rn->m_BlockLength);
							memcpy(There.data() + e, pp.data.data(),pp.data.size());

						}
						p0 += sizeof(RdcNeed);
					}

					// Check the hash
					HASH h;
					h.hash(There.data(), (DWORD)There.size());
					vector<char> lhash;
					h.get(lhash);
					if (lhash.size() != f.RHash.size())
						return E_FAIL;
					if (memcmp(lhash.data(), f.RHash.data(), lhash.size()) != 0)
						return E_FAIL;

					wstring old = f.Local + OldSuffix;
					DeleteFile(old.c_str());
					MoveFileEx(f.Local.c_str(), old.c_str(), MOVEFILE_COPY_ALLOWED);
					if (FAILED(PutFile(f.Local.c_str(), There)))
						return E_FAIL;
					MoveFileEx(old.c_str(), nullptr, MOVEFILE_DELAY_UNTIL_REBOOT);
				}
			}
			else
			if (mode == 2)
			{
				bool Has = false;
				// This is a zip containing the signatures
				ZIPUTILS::ZIP z(r.data(), r.size());
				vector<ZIPUTILS::mz_zip_archive_file_stat> l;
				if (FAILED(z.EnumFiles(l)) && r.size())
					return E_FAIL;
				for (auto& ll : l)
				{
					string guid = ll.m_filename;
					// Find it
					for (auto& f : files)
					{
						if (f.Remote == guid)
						{
							vector<char> d;
							if (FAILED(z.Extract(guid.c_str(), d)))
								return E_FAIL;
							if (d.empty())
								return E_FAIL;
							f.RSig = d;
							Has = true;
							break;
						}
					}
				}

				if (Has)
					return S_FALSE;
				return S_OK;
			}
			if (mode == 1)
			{
				// This is a zip containing the patched files, full
				ZIPUTILS::ZIP z(r.data(),r.size());
				vector<ZIPUTILS::mz_zip_archive_file_stat> l;
				if (FAILED(z.EnumFiles(l)))
					return E_FAIL;
				for (auto& ll : l)
				{
					string guid = ll.m_filename;
					// Find it
					for (auto& f : files)
					{
						if (f.Remote == guid)
						{
							vector<char> d;
							if (FAILED(z.Extract(guid.c_str(), d)))
								return E_FAIL;

							// Check the hash
							HASH h;
							h.hash(d.data(),(DWORD) d.size());
							vector<char> lhash;
							h.get(lhash);
							if (lhash.size() != f.RHash.size())
								return E_FAIL;
							if (memcmp(lhash.data(),f.RHash.data(),lhash.size()) != 0)
								return E_FAIL;

							wstring old = f.Local + L".{1DAE2EA5-922C-4049-AD37-0AA3E0EE7DC0}.old";
							DeleteFile(old.c_str());
							MoveFileEx(f.Local.c_str(), old.c_str(), MOVEFILE_COPY_ALLOWED);
							if (FAILED(PutFile(f.Local.c_str(),d)))
								return E_FAIL;
							MoveFileEx(old.c_str(), nullptr, MOVEFILE_DELAY_UNTIL_REBOOT);
							break;
						}
					}
				}
			}
			else
			if (mode == 0)
			{
				r.resize(r.size() + 1);
				const char* rmsg = r.data();
				int rr = atoi(r.data());
				if (rr != 200)
					return E_FAIL;

				// Check now strings
				rmsg += 4; // pass 200
				int count = atoi(rmsg);
				if (!count)
					return E_FAIL;
				rmsg = strchr(rmsg, ',');
				if (!rmsg)
					return E_FAIL;
				rmsg++;
				for (int i = 0; i < count; i++)
				{
					if (!rmsg)
						return E_FAIL;
					int v = atoi(rmsg);
					if (v == 331)
						return S_FALSE;
					if (v != 220)
						return E_FAIL;
					rmsg = strchr(rmsg, ',');
					if (rmsg)
						rmsg++;
				}
			}
			return S_OK;
		}

		HRESULT GetHashes()
		{
			if (FAILED(rest.Connect(endpoint.host.c_str(), endpoint.SSL, endpoint.Port, endpoint.flg, endpoint.user.c_str(), endpoint.pass.c_str())))
				return E_FAIL;

			string tmp = TempFileA();
			ZIPUTILS::ZIP z(tmp.c_str());
			vector<char> zipdata;

			for (auto& m : files)
			{
				vector<char> tt;
				HASH h;
				if (FAILED(LoadFile(m.Local.c_str(), tt)))
					h.hash("", 0);
				else
					h.hash(tt.data(), (DWORD)tt.size());

				vector<char> ht;
				h.get(ht);
				m.LHash = ht;
				memset(ht.data(), 0, ht.size());
				PTR item(ht);

				string f1 = "hash-" + m.Remote;
				if (FAILED(z.PutFile(f1.c_str(), item.p, (unsigned long)item.sz)))
					return E_FAIL;
			}
			LoadFileA(tmp.c_str(), zipdata);
			DeleteFileA(tmp.c_str());
			if (zipdata.empty())
				return E_FAIL;

			// Download also the hash list
			wstring hdr0 = L"X-Project: " + u(ProjectGuid.c_str());
			wstring hdr1 = L"X-Function: hashes";
			wstring hdr2 = L"Content-Type: application/octet-stream";
			wchar_t cl[1000];
			swprintf_s(cl, 1000, L"Content-Length: %zu", zipdata.size());
			wstring hdr3 = cl;
			auto hIX = rest.RequestWithBuffer(endpoint.path.c_str(), L"POST", { hdr0,hdr1,hdr2,hdr3 }, zipdata.data(), zipdata.size(), 0, 0, true, 0);
			vector<char> r;
			rest.ReadToMemory(hIX, r);

			ZIPUTILS::ZIP z2(r.data(), r.size());
			vector<ZIPUTILS::mz_zip_archive_file_stat> l;
			if (FAILED(z2.EnumFiles(l)))
				return E_FAIL;
			for (auto& ll : l)
			{
				vector<char> d;
				if (FAILED(z2.Extract(ll.m_filename, d)))
					return E_FAIL;
				if (d.empty())
					return E_FAIL;

				// Put the hash
				for (auto& f : files)
				{
					if (f.Remote == ll.m_filename)
					{
						f.RHash = d;
						break;
					}
				}
			}

			return S_OK;
		}

		HRESULT Upload(std::function<HRESULT(size_t, size_t, void*)> func = nullptr,void* lp = 0)
		{
			map<string, tuple<vector<char>, vector<char>,vector<char>>> data;
			for (auto& m : files)
			{
				tuple<vector<char>, vector<char>,vector<char>> t;
				if (FAILED(CreateDataForFile(m.Local.c_str(),t)))
					return E_FAIL;
				data[m.Remote] = t;
			}


			size_t numfiles = 0;
			string tmp = TempFileA();

			ZIPUTILS::ZIP z(tmp.c_str());

			for(auto& m : data)
			{
				numfiles++;
				PTR item = get<0>(m.second);
				PTR sig = get<1>(m.second);
				PTR name = get<2>(m.second);

				// put the item
				if (!item || !sig)
					return E_FAIL;
				
				string f1 = "fils-" + m.first;
				string f2 = "sigs-" + m.first;
				string f3 = "name-" + m.first;
				if (FAILED(z.PutFile(f1.c_str(), item.p, (unsigned long)item.sz)))
					return E_FAIL;
				if (FAILED(z.PutFile(f2.c_str(), sig.p, (unsigned long)sig.sz)))
					return E_FAIL;
				if (FAILED(z.PutFile(f3.c_str(), name.p, (unsigned long)name.sz)))
					return E_FAIL;
			}


			vector<char> zipdata;
			LoadFileA(tmp.c_str(), zipdata);
			DeleteFileA(tmp.c_str());
			if (zipdata.empty())
				return E_FAIL;

			// Upload
			if (FAILED(rest.Connect(endpoint.host.c_str(), endpoint.SSL, endpoint.Port, endpoint.flg, endpoint.user.c_str(), endpoint.pass.c_str())))
				return E_FAIL;
			
			wstring hdr0 = L"X-Project: " + u(ProjectGuid.c_str());
			wstring hdr1 = L"X-Function: upload";
			wstring hdr2 = L"Content-Type: application/octet-stream";
			wchar_t cl[1000];
			swprintf_s(cl, 1000, L"Content-Length: %zu", zipdata.size());
			wstring hdr3 = cl;
			swprintf_s(cl, 1000, L"X-Password: %s", endpoint.uploadpass.c_str());
			wstring hdr4 = cl;
			auto hIX = rest.RequestWithBuffer(endpoint.path.c_str(), L"POST", { hdr0,hdr1,hdr2,hdr3,hdr4 }, zipdata.data(), zipdata.size(),
				[&](size_t sent,size_t tot,void* lp) -> HRESULT
				{
					if (func)
						return func(sent, tot, lp);
					return S_OK;
				}
				, lp, false, 0);
			vector<char> r;
			rest.ReadToMemory(hIX, r);

			r.resize(r.size() + 1);
			const char* rmsg = r.data();
			int rr = atoi(rmsg);
			if (rr != 200)
				return E_FAIL;

			if (numfiles < files.size())
				return S_FALSE;
			return S_OK;
		}

		HRESULT CreateDataForFile(const wchar_t* f, tuple<vector<char>, vector<char>,vector<char>>& t)
		{
			t = make_tuple<vector<char>, vector<char>, vector<char>>({}, {}, {});
			if (FAILED(LoadFile(f,get<0>(t))))
				return E_FAIL;
			if (FAILED(CreateSignatureFor(f,get<1>(t))))
				return E_FAIL;

			// And a friendly name
			wchar_t xf[1000] = { 0 };
			wcscpy_s(xf, 1000, f);
			PathStripPath(xf);
			string aa = a(xf);
			get<2>(t).resize(aa.length());
			memcpy(get<2>(t).data(), aa.data(), aa.length());

			return S_OK;
		}

		HRESULT CreateDataForSelf(tuple<vector<char>, vector<char>,vector<char>>& t)
		{
			auto a = Self();
			return CreateDataForFile(a.c_str(), t);
		}





	};
}

