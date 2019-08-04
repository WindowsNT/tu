# TU
A C++ library for Windows for automatically updating your software, with differential update support.
Article at CodeProject: https://www.codeproject.com/Articles/5163875/TU-The-easy-update-library-for-your-projects

## WebServer setup
Change the three defines on top of tu.php to define database name, admin u+p.
Defaults:


```PHP
define('TU_DATABASE', "tu.db");
define('TU_ADMIN_USERNAME','root');
define('TU_ADMIN_PASSWORD','muhahah');
```

Upload to your server, go to tu.php?admin
Create a new project with a name and an upload password


## C++ Windows app
```C++
TU::TU tu("ea6bde7e-ee50-43a5-9a2c-4eb80a3630d1",L"www.example.org",L"/update2/tu.php",true,443,0,0,0,L"12345678");
```

Use the GUID shown by tu.php admin panel, your site, the path to tu.php, SSL/Port/U/P options, and the upload password you have used in the project creation if you plan to do any uploads.
The upload password will only be used from an "uploader" app; Don't enter the password in your distributed executable as users can sniff it.




```C++

// Add referenced files
vector<tuple<wstring, string>> tux;
auto a = L"m.docx";
tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(a), string("A44BC1B3-D919-4835-A7D8-FC633EB7B7EC")));
auto b = L"m.pdf";
tux.emplace_back(make_tuple<wstring, string>(forward<wstring>(b), string("A44BC1B3-D919-4835-A7D8-FC633EB7B7ED")));
tu.AddFiles(tux);

// Upload your stuff from an external uploader application (don't put this in a redistributed executable as it has to use your upload password)
tu.Upload();

// Check for updates
auto hr = tu.Check(); // S_FALSE if updates are there, S_OK if no updates needed.

// Check for updates with differential support
auto hr = tu.CheckWithSigs();

// Download updates, full method
auto hr = tu.DownloadFull();

// Download updates, differential method
auto hr = tu.DownloadDiff();

```



