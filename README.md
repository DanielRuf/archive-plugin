# WordPress Archive Plugin

**Always create a backup before using this plugin**

This plugin allows you to archive inactive plugins.

## Archiving a plugin:

* Click "Archive & Delete" to start the archive process
* The plugin files are compressed as archive
* A placeholder file is created with the original plugin information

## Unarchiving a plugin:

* Click "Unarchive" to start the unarchive process
* The archive is uncompressed
* The placeholder file is deleted

The password for the archive is the value of the `SECURE_AUTH_SALT` constant.

## Support

I provide only non-free support through email. Please check the troubleshooting section for known problems.

## Disclaimer

This plugin works for me in a few small test cases without any issues. As there is no feedback from other users and so it is not widely battle-tested it is wise to test the functionality and create a backup before using it.

## Troubleshooting

### Call to undefined method ZipArchive::setEncryptionName
This plugin uses the [ZipArchive](https://www.php.net/manual/en/class.ziparchive.php) class which requires PHP 7.2.0 or newer and libzip 1.2.0 or newer for [ZipArchive::setEncryptionName](https://www.php.net/manual/en/ziparchive.setencryptionname.php) (please check the version in the output of `phpinfo()`). Your hosting provider probably uses an old version of libzip so please ask them if they can provide you a setup with a newer version.

## Screenshots

### Plugin list view
![](list.png)

### Archived plugin
![](archived.png)

### Placeholder file with new description
![](description.png)