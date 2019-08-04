#include "stdafx.h"

#include "f:\\TOOLS\\tu8\\tu.hpp"

#include ".\\xml\\xml3all.h"
int main(int argc,char** argv)
{
	using namespace std;
	if (argc < 2)
	{
		printf("Usage: uploader <Name of Project>");
		exit(1);
	}
	XML3::XML x("u.xml");
	auto& pr = x.GetRootElement()["projects"];
	for (auto& p : pr)
	{
		if (p.vv("name").GetValue() == string(argv[1]))
		{
			printf("Uploading project %s...", argv[1]);
			TU::TU tu(p.vv("g").GetValue().c_str(), L"www.example.org", L"/update2/tu.php", true, 443, 0, 0, 0, p.vv("up").GetWideValue().c_str());

			vector<tuple<wstring, string>> tux;
			for (auto& f : p["files"])
			{
				wstring a = f.vv("n").GetWideValue();
				string g = f.vv("g").GetValue();
				tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(a), forward<string>(g)));
			}
			tu.AddFiles(tux);
			tu.Upload();
		}
	}
	return 0;
}