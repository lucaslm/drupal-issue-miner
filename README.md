drupal-issues-mining
====================

##  Description

A selenium driven php script to collect informations from the [drupal issue tracking website](https://www.drupal.org/project/issues/search/drupal). it uses 

##  Usage

To use it, follow the steps bellow:

* Clone the repository

        git clone git@github.com:lucaslm/drupal-issues-mining.git

* Download the composer.phar

        curl -sS https://getcomposer.org/installer | php

* Install webdriver

        php composer.phar install

* Download the selenium-server-standalone-#.jar file provided here:  http://selenium-release.storage.googleapis.com/index.html

* Run that file, replacing # with the current server version.

        java -jar selenium-server-standalone-#.jar

* Run the script

        php -f issueMiner.php
