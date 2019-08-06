#include "stdafx.h"

#include "..\\tu.hpp"

#include ".\\xml\\xml3all.h"
using namespace std;
int main(int argc,char** argv)
{
	using namespace std;
	if (argc < 2)
	{
		printf("Usage: uploader <Name of Project>");
		exit(1);
	}

	wchar_t mf[1000] = { 0 };
	GetModuleFileName(0, mf, 1000);
	PathRemoveFileSpec(mf);
	SetCurrentDirectory(mf);

	XML3::XML x("u.xml");
	auto& pr = x.GetRootElement()["projects"];
	for (auto& p : pr)
	{
		if (p.vv("name").GetValue() == string(argv[1]))
		{
			
			printf("Uploading project %s...", argv[1]);
			
			
			TU::TU tu(p.vv("g").GetValue().c_str(), L"www.turboirc.com", L"/update2/tu.php", true, 443, 0, 0, 0, p.vv("up").GetWideValue().c_str());
			vector<tuple<wstring, string>> tux;
			for (auto& f : p["files"])
			{
				wstring a = f.vv("n").GetWideValue();
				string g = f.vv("g").GetValue();
				tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(a), forward<string>(g)));
			}
			tu.AddFiles(tux);
			printf("\r\n");

			// Get Hashes
			auto hr = tu.GetHashes();
			if (FAILED(hr))
			{
				MessageBox(0, L"Cannot get hashes", 0, 0);
				printf("Hash error");
				exit(2);
			}

			// Remove files that have the same hash
			vector<TU::TUFILE>& fils = tu.GetFiles();
			for (int i = (int)fils.size() - 1; i >= 0; i--)
			{
				if (fils[i].LHash == fils[i].RHash)
					fils.erase(fils.begin() + i);
			}

			if (fils.empty())
			{
				printf("\r\nProject up to date\r\n");
				exit(0);
			}
			for (auto& fi : fils)
				wprintf(L"\r\n - %s", fi.Local.c_str());

			printf("\r\n");
			hr = tu.Upload([](unsigned long long cur, unsigned long long sz, void* lp) -> HRESULT {
				printf("\r");
				printf("Uploading %llu / %llu bytes...",cur,sz);
				return S_OK;
				});;
			
			if (FAILED(hr))
				MessageBox(0, L"Upload Failed", 0, 0);
			printf("\r\nEnd, return value: 0x%X\r\n",hr);
		}
	}
	return 0;
}