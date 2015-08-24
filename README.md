# Script rename_groups

This command line script, from a list of groupname pairs (old / new), loops in database table, searching for a specific column name ("gid", by default) to update groupnames.

## Install

This script uses `composer` to get access to librairies, that means you need internet access and to have `composer` installed on your platform (https://getcomposer.org/download/).

Just launch `composer install` to get the needed librarires installed (on a "vendor" directory).

## Configuration

The `rename_groups.php` script *must* be configured before running. The configuration keys are PHP contants in the top first lines of the script.
Each key is commented and should be straightforward.

The complete path to owncloud instance config.php is needed for the script to know if the owncloud is in maintenance mode or not. Only read access is needed.

## Input CSV file

A header line, then a list of old name, new name like :

```csv
old group name, new group name
groupe2,nvgrp2
groupe3,nvgrp3
```

A test_csv.csv file is provided.

## Run

Just run the script, there's no parameter nor option. All is in the configuration constant.

```bash
$ php rename_groups.php
```

## Tips

The primary objective was to be able to change group names in an owncloud v7 database but you should be able to use it in another contexts.
To use this script without owncloud, you need to comment the lines (93-97) that verify if owncloud is in mode maintenance. Adding two slashes in the beginning of the lines does the job :

```php
    // Maintenance ?
    if ($this->isMaintenanceMode()) {
        $this->cli->red()->out('Please, set maintenance mode before renaming groups. Exiting now.');
        exit;
    }
```

becomes

```php
//    // Maintenance ?
//    if ($this->isMaintenanceMode()) {
//        $this->cli->red()->out('Please, set maintenance mode before renaming groups. Exiting now.');
//        exit;
//    }
```

## License and Author

|                      |                                          |
|:---------------------|:-----------------------------------------|
| **Author:**          | Patrick Paysant (<patrick.paysant@linagora.com>)
| **Copyright:**       | Copyright (c) 2014 CNRS DSI
| **License:**         | AGPL v3, see the COPYING file.
