<?php

/**
 * Compares two versions
 */
function compareVersions($version1, $version2) {
  $version1 = preg_split("/\.|-/", $version1);
  $version2 = preg_split("/\.|-/", $version2);
  $count    = min(count($version1), count($version2));

  for ($i = 0; $i < $count; $i++) {
    if ($version1[$i] != $version2[$i]) {
      if ($version1[$i] == "x") {

        // version1 is like either 7.x or 7.x-dev
        if ($i == count($version1)-1) {

          // version1 is a entire branch (e.g. 7.x) and
          // contains any other version from the same branch.
          return 0;
        } else {

          // version1 is a branch's head (e.g. 7.x-dev)
          /*if ($version2[$i] == "x") {

            // version2 is like either 7.x or 7.x-dev
            // Therefore version1 contains version2 (either
            // properly or not)
            return 0;
          } else*/ {

            // version2 is a specific version (e.g. 7.15, 7.0-beta1).
            // Therefore it is less than the branch's head (version1).
            return 1;
          }
        }
      } else {

        // version1 is a specific version (e.g. 7.15, 7.0-beta1).
        if ($version2[$i] == "x") {

          // version2 is like either 7.x or 7.x-dev
          if ($i == count($version1)-1) {

            // version2 is a entire branch (e.g. 7.x) and 
            // contains any other version from the same branch.
            return 0;
          } else {

            // version2 is a branch's head (e.g. 7.x-dev) and
            // is greater than any specific version from this branch
            return -1;
          }
        } else {

          // version2 is a specific version (e.g., 7.15, 7.0-beta1).
          return ($version1[$i] < $version2[$i]) ? -1 : 1;
        }

      }
    }
  }

  return 0;
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

function validateArguments(&$options) {

  if (isset($options['h']) || isset($options['help'])) {
      echo ("Usage: php [-f] issueMiner.php [options]\n");
      echo ("  --minVersion  Since which version start collecting statistics.\n");
      echo ("                Must not be greater than MaxVersion, if there is any.\n");
      echo ("  --maxVersion  Up to which version continue collecting statistics.\n");
      echo ("                Must not be less than MinVersion, if there is any.\n");
      echo ("  -h, --help    This help,\n");
      echo ("\n");
      exit;
  }

  if (isset($options['minVersion'])) {
      if (!preg_match("/^\d((\.)?\d+)*(\.(-alpha\d+|-beta\d+|-rc\d+|x))?$/", $options['minVersion'])) {
        echo ("Invalid version ".$options['minVersion']."\n");
        echo ("\n");
        exit;
      }
  } else {
    $options['minVersion'] = false;
  }

  if (isset($options['maxVersion'])) {
      if (!preg_match("/^\d((\.)?\d+)*(\.(-alpha\d+|-beta\d+|-rc\d+|x))?$/", $options['maxVersion'])) {
        echo ("Invalid version ".$options['minVersion']."\n");
        echo ("\n");
        exit;
      }
  } else {
    $options['maxVersion'] = false;
  }

  if ($options['minVersion'] && $options['maxVersion'] && 
      $options['minVersion'] > $options['maxVersion']) {
      echo ($options['minVersion']." is greater than ".$options['maxVersion']."\n");
      echo ("\n");
      exit; 
  }

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

