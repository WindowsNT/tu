#include "stdafx.h"
#include "..\\tu.hpp"

const char* MyProject = "E40E52F9-D8B6-4EA8-BEB3-773988436A9E";
TU::TU tu(MyProject,L"www.example.org",L"/php/tu.php",true,443);

int __stdcall wWinMain(HINSTANCE, HINSTANCE, LPWSTR, int)
{
	using namespace std;
	WSADATA wData;
	WSAStartup(MAKEWORD(2, 2), &wData);
	CoInitializeEx(0, COINIT_APARTMENTTHREADED);

	vector<tuple<wstring, string>> tux;

	auto a = L"m.docx";
	tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(a), string("A44BC1B3-D919-4835-A7D8-FC633EB7B7EC")));
	auto b = L"m.pdf";
	tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(b), string("A44BC1B3-D919-4835-A7D8-FC633EB7B7ED")));
	tu.AddFiles({ tux });

	HRESULT hr = 0;
	// Upload 
	hr = tu.Upload();

	// Check if I have a newer version
	hr = tu.Check();
	if (hr == S_FALSE)
	{
		// There is something newer
		tu.DownloadFull();
	}

	hr = tu.CheckWithSigs();
	if (hr == S_FALSE)
	{
		// There is something newer
		tu.DownloadDiff();
	}
	return 0;
}


