drupal-issue-miner
====================

##  Description

A selenium driven php script to collect informations from the [drupal issue tracking website](https://www.drupal.org/project/issues/search/drupal).

##  Usage

To use it, follow the steps bellow:

* Clone the repository

        git clone https://github.com/lucaslm/drupal-issue-miner.git

* Download the composer.phar

        curl -sS https://getcomposer.org/installer | php

* Install dependencies

        php composer.phar install

* Run the script

        php -f issueMiner.php
