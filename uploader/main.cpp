#include "stdafx.h"

#include "..\\tu.hpp"

#include ".\\xml\\xml3all.h"
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
			auto hr = tu.Upload([](unsigned long long cur, unsigned long long sz, void* lp) -> HRESULT {
				printf("\r");
				printf("Uploading %llu / %llu bytes...",cur,sz);
				return S_OK;
				});;
			printf("End, return value: 0x%X\r\n",hr);
		}
	}
	return 0;
}