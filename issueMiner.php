#!/usr/bin/php
<?php

require_once('utils.inc');
require_once('modules.inc');
require_once('vendor/facebook/webdriver/lib/__init__.php');

/**
 * Finds all the stable versions between (inclusive), and return an associative
 * array information about them.
 * 
 * @param string $minVersion
 * @param string $maxVersion
 * @return array The array with each version. Each element is a major release,
 *               in which each element in turn is a minor release. The key is
 *               always the versions's name.
 */
function findVersions($minVersion = false, $maxVersion = false) {

  global $driver;
  global $now;
  global $wait;

  $versions = array();

  $driver->get('https://www.drupal.org/node/3060/release');

  // Select versions.
  $versionElement = $wait->until(
    WebDriverExpectedCondition::presenceOfElementLocated(
      WebDriverBy::id('edit-api-version')
    )
  );
  $versionSelect = new WebDriverSelect($versionElement);
  $versionOptions = $versionSelect->getOptions();
  foreach ($versionOptions as $versionOption) {
    $versionNumber = $versionOption->getText();
    if ((!$minVersion || compareVersions($minVersion, $versionNumber) <= 0) && 
        (!$maxVersion || compareVersions($versionNumber, $maxVersion) <= 0)) {
      $versionSelect->selectByValue($versionOption->getAttribute('value'));
    }
  }

  // Submit the form
  $submitElement = $driver->findElement(
      WebDriverBy::id('edit-submit-project-release-by-project')
  );
  $submitElement->submit();

  do {

    $containerDiv = $wait->until(
      WebDriverExpectedCondition::presenceOfElementLocated(
        WebDriverBy::xpath(
          "//div[@id='block-system-main']/div[@class='block-inner']/div[@class='content']"
        )
      )
    );

    $releases = $containerDiv->findElements(
      WebDriverBy::xpath(
        "div/div[@class='view-content']/div/div[contains(@class,'node-project-release')]"
      )
    );

    echo("Found ".count($releases)." versions.\n");

    if (count($releases)) {
      foreach($releases as $release) {

        // Gather version information
        $versionName = $release->findElement(
          WebDriverBy::xpath("h2")
        );
        $versionName = $versionName->getText();
        $versionName = str_ireplace("drupal ", "", $versionName);

        if ((!$minVersion || compareVersions($minVersion, $versionName) <= 0) && 
            (!$maxVersion || compareVersions($versionName, $maxVersion) <= 0)) {

          $majorVersion = substr($versionName, 0, strpos($versionName, '.')).".x";

          if (strpos($versionName, ".x-dev")) {
            $versionTimestamp = $now;
          } else {
            $versionTimestamp = $release->findElement(
              WebDriverBy::xpath("div[@class='submitted']/time")
            );
            $versionTimestamp = intval($versionTimestamp->getAttribute('datetime'));
          }

          echo("Adding version ".$versionName."\n");
          $versions[$majorVersion][$versionName] = array(
            'name'      => $versionName,
            'timestamp' => $versionTimestamp,
          );

        }
      }
    }

    $next_page_link = $containerDiv->findElements(
        WebDriverBy::xpath("div/div[@class='item-list']/ul[@class='pager']/li[@class='pager-next']/a")
    );
    if (count($next_page_link)) {
      $next_page_link[0]->click();
    }

  } while (count($next_page_link));

  return $versions;

}

function parseBug(array $version_info, array &$module_erros) {
  
  global $driver;
  global $now;
  global $wait;

  $main_container_div = $wait->until(
    WebDriverExpectedCondition::presenceOfElementLocated(
      WebDriverBy::id(
        "block-system-main"
      )
    )
  );

  $metadata_container_div = $wait->until(
    WebDriverExpectedCondition::presenceOfElementLocated(
      WebDriverBy::id(
        "block-project-issue-issue-metadata"
      )
    )
  );

  $pub_timestamp = $main_container_div->findElement(
    WebDriverBy::xpath(
      "div/div[@class='content']/div/div[@class='submitted']/time"
    )
  );
  $pub_timestamp = intval($pub_timestamp->getAttribute('datetime'));

  $status = $metadata_container_div->findElement(
    WebDriverBy::xpath(
      "div/div[@class='content']/div[contains(@class,'field-name-field-issue-status')]/div/div"
    )
  );
  $status = $status->getText();

  $module = $metadata_container_div->findElement(
    WebDriverBy::xpath(
      "div/div[@class='content']/div[contains(@class,'field-name-field-issue-component')]/div/div"
    )
  );
  $module = $module->getText();

  $priority = $metadata_container_div->findElement(
    WebDriverBy::xpath(
      "div/div[@class='content']/div[contains(@class,'field-name-field-issue-priority')]/div[@class='field-items']/div"
    )
  );
  $priority = $priority->getText();

  // Get the closure timestamp of the bug, if it is closed. Otherwise, simply
  // consider the closure timestamp as now, for simplifing comparisions later.
  $closure_timestamp = $now;
  if ((strpos($status, 'Closed') === 0) || (strpos($status, 'Fixed') === 0)) {
    $comments = $main_container_div->findElements(
      WebDriverBy::xpath(
        "div/div[@class='content']/section/div[contains(@class,'comment') and ./div[@class='content']/div/div/div/table/tbody/tr/td[contains(normalize-space(text()), \"".$status."\")]]"
      )
    );
    foreach ($comments as $comment) {
      //$transition_comment = $comment->findElements(
      //  WebDriverBy::xpath(
      //    "//div[@class='content']/div/div/div/table/tbody/tr/td[normalize-space(text())=\"&raquo; ".$status."\"]"
      //  )
      //);
      //if (count($transition_comment)) {
        $closure_timestamp = $comment->findElement(
          WebDriverBy::xpath(
            "div[@class='submitted']/time"
          )
        );
        $closure_timestamp = strtotime($closure_timestamp->getAttribute('datetime'), $now);
      //}
    }
  }
  
  // Search for the versions which were released during the time the bug was open.
  $affected_subVersion = array_filter(
    $version_info,
    function($subVersion) use ($pub_timestamp, $closure_timestamp) {
      return ($pub_timestamp < $subVersion['timestamp'] && $subVersion['timestamp'] < $closure_timestamp);
    }
  );
  foreach($affected_subVersion as $version_name => $specific_subVersion) {
    $module_erros[$version_name][$priority]++;
  }
  
  //echo("\t\tmodule: ".$module."\n");
  //echo("\t\tstatus: ".$status."\n");
  //echo("\t\tpriority: ".$priority."\n");
  
}

// Parses a few arguments
$options = getopt('h:', array('minVersion:', 'maxVersion:', 'help'));

validateArguments($options);

if (!ini_get('date.timezone')) {
  // If there is no timezone is set, set one so date functions don't issue warnings.
  date_default_timezone_set('UTC');
}

// Main stript which uses php-webdriver.

// start Firefox with 5 second timeout
$host = 'http://localhost:4444/wd/hub'; // this is the default
$capabilities = DesiredCapabilities::firefox();
$driver = RemoteWebDriver::create($host, $capabilities, 10000);
$wait = new WebDriverWait($driver);

$now      = time();
$versions = findVersions($options['minVersion'], $options['maxVersion']);

foreach ($versions as &$version) {
  uksort($version, "compareVersions");
}
ksort($versions);
//echo var_export($versions, true);

$versions_7x = $versions['7.x'];

// navigate to 'https://www.drupal.org/project/issues/search/drupal'
$driver->get('https://www.drupal.org/project/issues/search/drupal');

$header  = ';'.implode(';;;;', array_keys($versions_7x))."\n";
$header .= ';'.implode(';', array_fill(0, count($versions_7x), 'Critical;Major;Normal;Minor'))."\n";
print_string($header);

foreach($modules as $module) {
  
  $module_errors = array_fill_keys(
      array_keys($versions_7x),
      array(
          'Critical' => 0,
          'Major'    => 0,
          'Normal'   => 0,
          'Minor'    => 0,
      )
  );
  
  // Select version.
  $versionElement = $driver->findElement(
      WebDriverBy::id('edit-version')
  );
  $versionSelect = new WebDriverSelect($versionElement);
  $versionSelect->selectByValue('7.x');

  // Select module.
  $moduleElement = $driver->findElement(
      WebDriverBy::id('edit-component')
  );
  $moduleSelect = new WebDriverSelect($moduleElement);
  $moduleSelect->deselectAll();
  $moduleSelect->selectByVisibleText($module);

  // Select category.
  $categoryElement = $driver->findElement(
      WebDriverBy::id('edit-categories')
  );
  $categorySelect = new WebDriverSelect($categoryElement);
  $categorySelect->selectByVisibleText("Bug report");

  // Select all status except for duplicated bugs, non reproducible bugs and bugs working as designed.
  $statusElement = $driver->findElement(
    WebDriverBy::id('edit-status')
  );
  $statusSelect = new WebDriverSelect($statusElement);
  $statusOptions = $statusSelect->getOptions();
  foreach ($statusOptions as $statusOption) {
    $statusSelect->selectByValue($statusOption->getAttribute('value'));
  }
  $statusSelect->deselectByVisibleText("Closed (duplicate)");
  $statusSelect->deselectByVisibleText("Closed (works as designed)");
  $statusSelect->deselectByVisibleText("Closed (cannot reproduce)");

  // Submit the form
  $submitElement = $driver->findElement(
      WebDriverBy::id('edit-submit-project-issue-search-project-searchapi')
  );
  $submitElement->submit();
  
  do {
    
    $containerDiv = $wait->until(
      WebDriverExpectedCondition::presenceOfElementLocated(
        WebDriverBy::xpath(
          "//div[@id='block-system-main']/div[@class='block-inner']/div[@class='content']/div[contains(@class,'view')]"
        )
      )
    );
    
    $bug_links = $containerDiv->findElements(
        WebDriverBy::xpath("div[@class='view-content']/table[2]/tbody/tr/td[contains(@class,'views-field-title')]/a")
    );
    if (count($bug_links)) {
      echo("Should process ".count($bug_links)." bugs from ".$module."\n");
      foreach($bug_links as $bug_link) {
        echo("\tParsing bug ".$bug_link->getAttribute('href')."\n");
        if (($previous_handle = openInNewWindow($driver, $bug_link)) !== false) {
          parseBug($versions_7x, $module_errors);
          goToWindow($driver, $previous_handle);
        }
      }
    }

    $next_page_link = $containerDiv->findElements(
        WebDriverBy::xpath("div[@class='item-list']/ul[@class='pager']/li[@class='pager-next']/a")
    );
    if (count($next_page_link)) {
      $next_page_link[0]->click();
    }

  } while (count($next_page_link));
  
  // We print into the file a module each time so if an exception occurs 
  // in the middle of the execution, the previous computation is stored.
  print_results($module, $module_errors);

}

// close the Firefox
$driver->quit();
