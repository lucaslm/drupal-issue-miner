<?php

function waitForNextPage($pWDSession) {
  $aWait = new PHPWebDriver_WebDriverWait($pWDSession);
  // wait for footer to not be available anymore
  $aWait->until(
      function($pWDSession) {
        return (0 === count(
            $pWDSession->elements(
                PHPWebDriver_WebDriverBy::CSS_SELECTOR, "#footer,#mx-footer"
            )
        ));
      }
  );
  // wait for footer to be available again
  $aWait->until(
      function($pWDSession) {
        return (0 < count(
            $pWDSession->elements(
                PHPWebDriver_WebDriverBy::CSS_SELECTOR, "#footer,#mx-footer"
            )
        ));
      }
  );
}

/**
 * Open a link in a new window, shiching the driver's handle
 *
 * @param WebDriver $driver The webDriver representing the current session.
 * @param mixed $link Either a string with the link to open or a WebDriverElement
 * representing the link itselft.
 * @return mixed The previous window handle as a string or false if $link was a
 * invalid type or a error ocorred.
 */
function openInNewWindow(RemoteWebDriver $driver, $link) {
  if ($link instanceof WebDriverElement && $link->getTagName() == 'a') {
    $link = $link->getAttribute('href');
  }
  
  if (is_string($link)) {
    // A simple window.open can be prevented by pop-up blocking options.
    // We must create an anchor in the current page and then click it.
    $script = "var d=document,a=d.createElement('a');a.target='_blank';a.href='$link';a.innerHTML='.';a.id='openInNewWindow';d.body.appendChild(a); return a;";
    $sScriptResult = $driver->executeScript($script, array());
    if ($sScriptResult && isset($sScriptResult['ELEMENT'])) {
      $anchor =
        new RemoteWebElement(
          new RemoteExecuteMethod($driver),
          $sScriptResult['ELEMENT']
        );
      /*$anchor = $driver->findElement(
        WebDriverBy::id(
          "openInNewWindow"
        )
      );*/
      $anchor->click();
      
      $script = "var a=arguments[0];a.parentNode.removeChild(a);";
      $sScriptResult = $driver->executeScript($script, array($anchor));
      
      $previous_handle = $driver->getWindowHandle();
      $handles         = $driver->getWindowHandles();
      $current_handle  = $handles[count($handles)-1];
      $driver->switchTo()->window($current_handle);
      $driver->get($link);
      
      return $previous_handle;
      
    }
  }
  return false;
}

function goToWindow(WebDriver $driver, $previous_handle) {
  $driver->close();
  $driver->switchTo()->window($previous_handle);
}

function parseBug(array $version_info, array &$module_erros) {
  
  global $driver; // this is the default
  global $now;
  
  $main_container_div = $driver->findElement(
    WebDriverBy::id(
      "block-system-main"
    )
  );
  $metadata_container_div = $driver->findElement(
      WebDriverBy::id(
        "block-project-issue-issue-metadata"
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
  
  // Search for the versions which were released during the time the bug was opened.
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

/**
 * Append the computed amount of erros found for a module into a file.
 * @param string  $module
 * @param array   $module_errors
 * @param string  $filename
 */
function print_results($module, array $module_errors, $filename = 'results.csv') {
  $output = format_results($module, $module_errors);
  
  // Write the results.
  print_string($output, $filename);
}

/**
 * Turn the errors results of a given module into a string for printing.
 * 
 * @param string $module
 * @param array $module_errors
 * @return string
 */
function format_results($module, array $module_errors) {
  $output  = '';
  $output .= $module.";";
  $module_errors = array_map(
    function($module_priority_errors) {
      return implode(";", $module_priority_errors);
    },
    $module_errors
  );
  $output .= implode(";", $module_errors);
  $output .= "\n";
  return $output;
}

function print_string($output, $filename = 'results.csv') {
  if (!($file = fopen($filename, 'a'))) {
    echo "Could not open ".$filename." for writing!\n";
    print_r($output);
  }
  else {
    if (!(fwrite($file, $output))) {
      echo "Could not write into ".$filename."\n";
      print_r($output);
    }
  }
  fclose($file);
}

// Main stript which uses php-webdriver.

require_once('lib/__init__.php');

// start Firefox with 5 second timeout
$host = 'http://localhost:4444/wd/hub'; // this is the default
$capabilities = DesiredCapabilities::firefox();
$driver = RemoteWebDriver::create($host, $capabilities, 10000);

// navigate to 'https://www.drupal.org/project/issues/search/drupal'
$driver->get('https://www.drupal.org/project/issues/search/drupal');

// adding cookie
$driver->manage()->deleteAllCookies();
$driver->manage()->addCookie(array(
  'name' => 'cookie_name',
  'value' => 'cookie_value',
));
$cookies = $driver->manage()->getCookies();
//print_r($cookies);

$modules = array(
  //'action.module',
  'aggregator.module',
  //'archive.module',
  //'ban.module',
  //'basic_auth.module',
  'block.module',
  'blog.module',
  'blogapi.module',
  'book.module',
  //'breakpoint.module',
  //'ckeditor.module',
  //'color.module',
  'comment.module',
  //'config.module',
  //'config_translation.module',
  //'contact.module',
  //'content_translation.module',
  //'contextual.module',
  'custom_block.module',
  //'dashboard.module',
  //'datetime.module',
  'dblog.module',
  //'drupal.module',
  //'editor.module',
  //'entity_reference.module',
  'field_ui.module',
  'file.module',
  'filter.module',
  'forum.module',
  //'hal.module',
  //'help.module',
  //'history.module',
  'image.module',
  //'jsonld.module',
  //'language.module',
  //'layout.module',
  //'link.module',
  //'locale.module',
  //'menu.module',
  //'menu_link.module',
  'number.module',
  //'openid.module',
  'options.module',
  //'overlay.module',
  //'page.module',
  //'path.module',
  //'php.module',
  //'poll.module',
  //'profile.module',
  //'quickedit.module',
  //'rdf.module',
  //'responsive_image.module',
  //'rest.module',
  //'search.module',
  //'serialization.module',
  //'shortcut.module',
  'simpletest.module',
  //'statistics.module',
  //'story.module',
  //'syslog.module',
  'system.module',
  'taxonomy.module',
  //'telephone.module',
  'text.module',
  //'throttle.module',
  //'toolbar.module',
  //'tour.module',
  //'tracker.module',
  //'translation.module',
  //'translation_entity.module',
  //'trigger.module',
  //'update.module',
  //'upload.module',
  'user.module',
  'views.module',
  'views_ui.module',
  //'watchdog.module',
  //'edit.module',
  'node system',//node.module?
  'field system',//field.module?
);

if (!ini_get('date.timezone')) {
  // If there is no timezone is set, set one so date funcions don't issue warnings.
  date_default_timezone_set('UTC');
}

$now = time();

$versions_8x = array(
  '8.0-alpha12' => array( 
    'timestamp' => strToTime( '2014-05-28 10:56:47 +0100', $now )
  ),
  '8.0-alpha11' => array( 
    'timestamp' => strToTime( '2014-04-23 10:10:16 +0100', $now )
  ),
  '8.0-alpha10' => array( 
    'timestamp' => strToTime( '2014-03-18 10:17:33 +0000', $now )
  ),
  '8.0-alpha9'  => array( 
    'timestamp' => strToTime( '2014-02-18 23:05:59 +0000', $now )
  ),
  '8.0-alpha8'  => array( 
    'timestamp' => strToTime( '2014-01-22 00:50:13 -0800', $now )
  ),
  '8.0-alpha7'  => array( 
    'timestamp' => strToTime( '2013-12-18 15:22:36 -0500', $now )
  ),
  '8.0-alpha6'  => array( 
    'timestamp' => strToTime( '2013-11-22 13:56:50 +0000', $now )
  ),
  '8.0-alpha5'  => array( 
    'timestamp' => strToTime( '2013-11-18 21:15:00 -0400', $now )
  ),
  '8.0-alpha4'  => array( 
    'timestamp' => strToTime( '2013-10-18 09:54:56 +0100', $now )
  ),
  '8.0-alpha3'  => array( 
    'timestamp' => strToTime( '2013-09-04 12:09:19 +0100', $now )
  ),
  '8.0-alpha2'  => array( 
    'timestamp' => strToTime( '2013-06-24 11:08:43 +0100', $now )
  ),
  '8.0-alpha1'  => array( 
    'timestamp' => strToTime( '2013-05-19 19:03:32 -0700', $now )
  ),
);

// Array with release dates of all versions 7.x, obtained by using the following command:
// git log --tags --simplify-by-decoration --pretty="format:%ai %d"
$versions_7x = array(
  '7.28'        => array( 
    'timestamp' => strToTime( '2014-05-08 00:05:00 -0400', $now )
  ),
  '7.27'        => array( 
    'timestamp' => strToTime( '2014-04-16 17:44:34 -0400', $now )
  ),
  '7.26'        => array( 
    'timestamp' => strToTime( '2014-01-15 14:43:16 -0500', $now )
  ),
  '7.25'        => array( 
    'timestamp' => strToTime( '2014-01-02 19:28:46 -0500', $now )
  ),
  '7.24'        => array( 
    'timestamp' => strToTime( '2013-11-20 15:45:59 -0500', $now )
  ),
  '7.23'        => array( 
    'timestamp' => strToTime( '2013-08-07 22:04:26 -0400', $now )
  ),
  '7.22'        => array( 
    'timestamp' => strToTime( '2013-04-03 17:29:52 -0400', $now )
  ),
  '7.21'        => array( 
    'timestamp' => strToTime( '2013-03-06 19:04:18 -0500', $now )
  ),
  '7.20'        => array( 
    'timestamp' => strToTime( '2013-02-20 15:32:50 -0500', $now )
  ),
  '7.19'        => array( 
    'timestamp' => strToTime( '2013-01-16 16:45:48 -0500', $now )
  ),
  '7.18'        => array( 
    'timestamp' => strToTime( '2012-12-19 13:52:59 -0500', $now )
  ),
  //'origin/9.x'  => array( 
  //  'timestamp' => strToTime( '2012-12-04 10:50:53 -0800' )
  //),
  '7.17'        => array( 
    'timestamp' => strToTime( '2012-11-07 16:42:13 -0500', $now )
  ),
  '7.16'        => array( 
    'timestamp' => strToTime( '2012-10-17 16:45:04 -0400', $now )
  ),
  '7.15'        => array( 
    'timestamp' => strToTime( '2012-08-01 12:27:42 -0400', $now )
  ),
  '7.14'        => array( 
    'timestamp' => strToTime( '2012-05-02 15:10:42 -0700', $now )
  ),
  '7.13'        => array( 
    'timestamp' => strToTime( '2012-05-02 15:01:31 -0700', $now )
  ),
  '7.12'        => array( 
    'timestamp' => strToTime( '2012-02-01 14:03:14 -0800', $now )
  ),
  '7.11'        => array( 
    'timestamp' => strToTime( '2012-02-01 13:29:51 -0800', $now )
  ),
  '7.10'        => array( 
    'timestamp' => strToTime( '2011-12-05 17:18:55 -0500', $now )
  ),
  '7.9'         => array( 
    'timestamp' => strToTime( '2011-10-26 12:53:40 -0700', $now )
  ),
  '7.8'         => array( 
    'timestamp' => strToTime( '2011-08-31 11:40:12 -0700', $now )
  ),
  '7.7'         => array( 
    'timestamp' => strToTime( '2011-07-27 17:02:24 -0700', $now )
  ),
  '7.6'         => array( 
    'timestamp' => strToTime( '2011-07-27 13:19:38 -0700', $now )
  ),
  '7.5'         => array( 
    'timestamp' => strToTime( '2011-07-27 13:17:40 -0700', $now )
  ),
  '7.4'         => array( 
    'timestamp' => strToTime( '2011-06-29 18:20:10 -0700', $now )
  ),
  '7.3'         => array( 
    'timestamp' => strToTime( '2011-06-29 18:12:24 -0700', $now )
  ),
  '7.2'         => array( 
    'timestamp' => strToTime( '2011-05-25 13:41:42 -0700', $now )
  ),
  '7.1'         => array( 
    'timestamp' => strToTime( '2011-05-25 13:07:13 -0700', $now )
  ),
  '7.0'         => array( 
    'timestamp' => strToTime( '2011-01-05 06:17:58 +0000', $now )
  ),
  '7.0-rc-4'    => array( 
    'timestamp' => strToTime( '2010-12-30 04:35:00 +0000', $now )
  ),
  '7.0-rc-3'    => array( 
    'timestamp' => strToTime( '2010-12-23 10:11:33 +0000', $now )
  ),
  '7.0-rc-2'    => array( 
    'timestamp' => strToTime( '2010-12-11 20:59:12 +0000', $now )
  ),
);

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
    
    $containerDiv = $driver->findElement(
        WebDriverBy::xpath(
            "//div[@id='block-system-main']/div[@class='block-inner']/div[@class='content']/div[contains(@class,'view')]"
        )
    );
    
    $bug_links = $containerDiv->findElements(
        WebDriverBy::xpath("//div[@class='view-content']/table[2]/tbody/tr/td[contains(@class,'views-field-title')]/a")
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
        WebDriverBy::xpath("//div[@class='item-list']/ul[@class='pager']/li[@class='pager-next']/a")
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
