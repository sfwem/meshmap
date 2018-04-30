<img src="https://mapping.kg6wxc.net/meshmap/images/MESHMAP_LOGO.svg" style="float:left; vertical-align: middle;"/><h1 style="float: left; vertical-align: middle;">MeshMap</h1>  
<br/>
<br/>
<br/>
<br/>
<br/>
Automated mapping of AREDN Networks.  
2016-2018 - Eric Satterlee / KG6WXC  
Addtional Credit to: Mark/N2MH and Glen/K6GSE for their work on this project.  
Licensed under GPL v3  
Donations or Beer accepted! :) (paypal coming soon, email: kg6wxc@gmail.com in the meantime.)

[Demo Map](https://mapping.kg6wxc.net/meshmap)

## Requirements
---------------
- **Apache webserver**  
(or equiv)  
- **PHP**  
- **PHP mysqli extension**   
- **MySQL/MariaDB**  
(Other database systems are up to you)
- **An AREDN Mesh node available over the local network**  
(Preferably connected to an AREDN network...)  
- **Map Tiles**  
(Either in a static directory or available via some tile server...)  
- **RPi3 or better**  
(The DB access can be pretty slow on an RPi1, you can move the DB to another system though...)
- **Patience**  
(Perhaps a lot!)
- **Familiarity with Linux/Apache/SQL**  
(You don't need to be a pro, but this should not be your first trip to a command line)  

<blockquote style="background: #d3d3d3;">In theory, this <em>should</em> run on a Windows system as well.<br>
It does not require anything specific to Linux (<em>Perhaps with the exception of cron</em>).<br>
PHP is PHP after all.</blockquote>  

### Map Tile Server info
**Without a map tile server or static tiles, Mesh users without internet access on their systems may not see any map tiles.**  
On the mesh, you *cannot* expect the client to have internet access in order to retrieve the tiles, you must provide them yourself, one way or another.  
The main map webpage will try to check for internet access and load the appropriate maps.  
Default internet map tile servers have been provided in the ini file, but the ini file will need tweaking if you want to use "local" tile servers or directories.  

It is *way* beyond the scope of this README file to help in setting up a map tile server.  
You are unfortunatly on your own there.  
It is a time consuming and computationaly expensive process, but can be done on "normal" hardware.  
It also takes *100's of GB of HD space* if you want to map the entire world, and that *does not* include the tiles themselves, that is only for the data required to **create** the map tiles.  
A good place to start for more info is: [https://switch2osm.org/serving-tiles/](https://switch2osm.org/serving-tiles/)  
If you attempt it, be patient, you *will* get it wrong more than a few times but in the end you might be surprised. :)  

<blockquote style="background: #d3d3d3;">Another option is that some programs, Xastir in particular, will save the map tiles they use.<br>
You *can* use those tiles as well, but they must be made to be accessible by your webserver.</blockquote>  

You *might* be able to convice KG6WXC to create local map tiles for you, if the area you want is in the USA... It literally takes *days* to make them though!  

## Initial setup for a freshly installed Raspbian 9 (Stretch) system
----------
(*Should* work for other Linuxes as well, change where needed)

- **1: Clone the projects directory from the git repository and enter it**  
`git clone https://mapping.kg6wxc.net/git/meshmap ; cd meshmap`

- **2: Import the SQL file to create the database**  
*Example*: `sudo mysql < node_map.sql`

- **3: Create a user for the database, you might have to login to the mysql server as root.**  
Here is an example of creating a mySQL user and granting access to the node_map database:
> `sudo mysql`  
> `CREATE USER 'mesh-map'@'localhost' IDENTIFIED BY 'password';`  
> `GRANT SELECT, DELETE, DROP, INSERT, TRIGGER, UPDATE on node_map.* TO 'mesh-map'@'localhost';`  
> `FLUSH PRIVLEGES;`

- **4: Decompress user-files.tar.gz**  
*Example*: `tar -zxvf user-files.tar.gz`  
This will decompress the files for the settings.  
They are distributed in compressed format so that *your* files do not get overwritten when the rest of the scripts get updated.  
(If anyone has a better idea for how to keep the user edited files updated and "away" from the git repo,  
yet still in the same directory and allow for not changing the users edits, it'd be great to hear about it)
    * The file scripts/user-settings.ini is probably the most important.  
    It is **very important** to make sure your SQL username and password are correct in scripts/user-settings.ini!!
    * Also important is, if the system that this is running on cannot resolve "localnode.local.mesh" you can change that in the user-settings.ini file.  
    * There are many other things you can change in the ini files. Default Map center position, the header messages, etc.  
    * *Please read* the comments in the user-settings.ini file for more info about the different settings.  
    * There is also a "custom.inc" PHP file that can be used for more site specific overrides if needed.  
<blockquote style="background: #FFFF99;">The way the user editable files are distrubuted will change in the near future, for now use this method.<br>
I will <em>always</em> strive to not overwrite your site changes when I make updates to certain files.</blockquote>  

- **4.5: To make sure it is all working at this point is probably a good idea.**  
You should now be able to run get-map-info.php from the scripts directory.  
I would suggest giving it a test run or two first.  
Node polling can take lots of time, espessialy on a large network. Be Patient! :)  
Enter the meshmap/scripts directory.  
    <blockquote style="background: #66CC66;">Tip: if you get a "command not found" error, you may need to run it like this:<br> `./get-map-info.php <option>` </blockquote>
These are options you can send to get-map-info.php:
    > `--test-mode-no-sql`  
Output to console only, *do not* read/write to the database.  
    <blockquote style="background: #FFFF99;">This will make sure the scripts can reach your localnode and the rest of the network.  
    This will **not** update the database, you won't see anything on the map page yet, it only outputs to the terminal for testing.</blockquote>
    > `--test-mode-with-sql`  
Output to console *and* read/write to the database.  
    <blockquote style="background: #FFFF99;">This will ensure everything is setup correctly and get some data into your database!</blockquote>
    <blockquote style="background: #66CC66;">Tip: <em><strong>Do not</strong></em> ctrl-C out of the script while it is using the database!<br>
	Let it finish, even if it seems hung up.<br>
	You should recieve some error if something is <em>actually</em> wrong.<br>
	Using ctrl-C to stop the script midway will cause problems in the database, <em>do not</em> do it!</blockquote>
<blockquote style="background: #d3d3d3;">If the --test-mode-no-sql is successful, you can go ahead and run the script with --test-mode-with-sql or just without any options.<br>
Run the script without options and there is no on screen output (this is for cron).</blockquote>  

- **5: Copy httpd-meshmap.conf to the apache2 "Conf Available" directory**, `/etc/apache2/conf-available`  
Once the file is copied, you need to edit it and make sure the `<Alias>` and `<Directory>` directives have the correct paths.  
After you have made sure the file is correct then run: `sudo a2enconf httpd-meshmap`  
This is will load the config into Apache and if successful, it will tell you to reload apache, do so.  
    <blockquote style="background: #d3d3d3;"><em>Other linux distibutions may require you to copy this file into /etc/httpd/extra<br>and then edit /etc/httpd/httpd.conf and add the line:</em> `Include extra/httpd-meshmap.conf` <em>somewhere.</em></blockquote>  

- **6: Load up the page: http://myhostname/meshmap/index.php and you should hopefully see your data.**  
You may or may not see any map tiles, depending on if the system you are using to view the page has access to the internet or not.  
Even without map tiles, you should still see your data being mapped out.  

- **7: The cronscript.sh file is to automatically run the polling script and can be run from cron every minute.**  
(or at whatever interval you choose)  
Copy the cronscript.sh file out of the meshmap directory and place it in the home directory of the user running the scripts.  
Then, you **must** edit the cronscript.sh file and make sure the path it uses to get to the scripts directory is correct!  
After that, create a cron entry with `crontab -e`  
A cron entry is as easy as this: `* * * * * /home/pi/cronscript.sh`  
    <blockquote style="background: #d3d3d3;">You *can* safely run the script every minute in cron like this.<br>It won't actually do anything unless the intervals specified in the ini file have expired.</blockquote>  
  
## Updating the scripts
----------
Simply run a "git pull" from the meshmap directory and the scripts will be updated from the git repo.  
The user-settings.inc, meshmap-settings.ini, cronscript.sh, and custom.inc files will *not* be affected by updating.  
The settings in the ini files *may* still change in future versions.  
For now tho, if the ini files change, and you still have the old ones in use, things will probably break! Be Warned!  
Hopefully in the future this process can be more automated.  
  
If you make changes beyond the user editable files I encourage you to perhaps push the changes upstream, please contact kg6wxc@gmail.com if you wish to do so.  
    <blockquote style="background: #d3d3d3;">I am making changes all the time, it would be a good idea to run "git pull" from time to time to find any updates.</blockquote>  

## Notes on usage of the map pages
----------
http://(hostname)/meshmap/node_report.php will show you all the info in the DB without trying to map anything.  
This can be useful to see if all the data is there or to find nodes that have no location set. (or other issues)  
    
There is an "admin" page, which is still in the works, what is there now does work tho.  
Try to load up: http://(hostname)/meshmap/admin/admin.php in your web browser.  
I've tried to provide instructions on the admin pages themselves.  
From the admin pages you can "fix" a nodes location, which can be helpful for those users that forget the "-" in front of their longitude. :)  
The admin pages also allow for the addition of "Non Mesh" Markers, fire stations, police stations, EOC's , etc...  

## ToDo List
----------
(In no particular order)  
- [x] Add new MeshMap Logo.  
- [ ] Change the user editable files to be distributed with "-default" added to the extension, no more tar.gz file.  
- [ ] Use a cookie instead of _POST for the internet check (No more stupid dialog box on refresh).  
- [ ] Make "Parallel Threads" work again in get-map-info script, with limits on how many can be run at once (this will greatly speed up network polling).  
- [ ] Changes so sbarc.org can have the new version too!  
- [ ] Change css file for the "?" slide-out menu.  
- [ ] Fix a typo in the attribution bar so the pop-up for the links is only for the links number.  
- [ ] Implement N2MH's "Link aging" idea(s).  
- [ ] The "Planning" Tab.  
- [ ] Make it so other networks can export their data for use on a "Mega Map" type page. :)
  
## Contributing
----------
**Contribution is encouraged!!**  
I can't think of *everything*!  
If you find an improvement, typo, or whatever, please, send an email to kg6wxc@gmail.com and we can get you setup with write access if you'd like!  

This README file last updated: april 2018
