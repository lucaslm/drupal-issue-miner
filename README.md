drupal-issues-mining
====================

##  Description

A selenium driven php script to collect informations from the [drupal issue tracking website](https://www.drupal.org/project/issues/search/drupal).

##  Usage

To use it, follow the steps bellow:

* Clone the repository

    git clone git@github.com:lucaslm/drupal-issues-mining.git

* Download the composer.phar

    curl -sS https://getcomposer.org/installer | php

* Install webdriver

    php composer.phar install

* Run it

    php -f issueMiner.php
