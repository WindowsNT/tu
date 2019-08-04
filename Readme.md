# TU
A C++ library for Windows for automatically updating your software, with differential update support.
Article at CodeProject: https://www.codeproject.com/script/Articles/ArticleVersion.aspx?waid=4142876&aid=5163875

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
Use the GUID shown by tu.php admin panel, your site, the path to tu.php, SSL/Port/U/P options, and the upload password you have used in the project creation



