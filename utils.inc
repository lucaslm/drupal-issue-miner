<?php

/**
 * Compares two versions
 */
function compareVersions($version1, $version2) {

  // Array to store the order of pre-regular-releases versions.
  $preReleases = array_flip(array(
    "x-dev", "unstable", "alpha", "beta", "rc"
  ));
  // All values in preReleases must be negative so they come before regular releases.
  $count = count($preReleases);
  foreach ($preReleases as &$preRelease) {
    $preRelease -= $count;
  }

  $patterns     = array("/0+-unstable(-)?(\d+)$/", "/0+-alpha(-)?(\d+)$/", "/0+-beta(-)?(\d+)$/", "/0+-rc(-)?(\d+)$/");
  $replacements = array("unstable.$2",             "alpha.$2",             "beta.$2",             "rc.$2");

  $version1 = preg_replace($patterns, $replacements, $version1);
  $version2 = preg_replace($patterns, $replacements, $version2);

  $version1 = explode(".", $version1);
  $version2 = explode(".", $version2);

  $count    = min(count($version1), count($version2));

  for ($i = 0; $i < $count; $i++) {
    if ($version1[$i] != $version2[$i]) {
      if ($version1[$i] == "x" || $version2[$i] == "x") {

          // Some of the versions is a entire branch (e.g. 7.x)
          // and contains any other version from the same branch.
          return 0;
      } else {
          $subVersion1 = is_numeric($version1[$i])?$version1[$i]:$preReleases[$version1[$i]];
          $subVersion2 = is_numeric($version2[$i])?$version2[$i]:$preReleases[$version2[$i]];
          return ($subVersion1 < $subVersion2) ? -1 : 1;
      }
    }
  }

  return 0;
}

function parseCurlHeaders(&$headers) {
  $headers = explode("\n", $headers);
  list($version, $status) = explode(" ", $headers[0], 2);
  $parsedHeaders = array(
    'status'  => $status,
    'version' => $version,
  );
  unset($headers[0]);
  foreach ($headers as $header) {
    list($field, $value) = explode(":", $header, 2);
    $parsedHeaders[$field] = $value;
  }
  return $headers = $parsedHeaders;
}

function makeGitHubApiRequest($cURLhandle, $url,
                              $username = false, $password = "", $timeout = 3600) {
  curl_setopt($cURLhandle, CURLOPT_URL, $url); 
  curl_setopt($cURLhandle, CURLOPT_HEADER, 1);
  curl_setopt($cURLhandle, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($cURLhandle, CURLOPT_USERAGENT, "curl");

  if ($username) {
    curl_setopt($cURLhandle, CURLOPT_USERPWD, "$username:$password");
    curl_setopt($cURLhandle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
  }

  $response = curl_exec($cURLhandle);
  list($headers, $content) = explode("\r\n\r\n", $response, 2);
  parseCurlHeaders($headers);

  if ($headers['status'] == 403 && $headers['X-RateLimit-Remaining'] < 0) {
    // Wait as much as it takes to get the request serviced.
    $waitTime = $headers['X-RateLimit-Reset'] - time();
    if ($waitTime <= $timeout) {
      sleep($waitTime);
      $response = curl_exec($cURLhandle);
    }
  }

  return $response;
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
      echo("Usage: php [-f] issueMiner.php [options]\n");
      echo("  --minVersion      Since which version start collecting statistics.\n");
      echo("                    Must not be greater than MaxVersion, if there is any.\n");
      echo("  --maxVersion      Up to which version continue collecting statistics.\n");
      echo("                    Must not be less than MinVersion, if there is any.\n");
      echo("  --githubUser, --githubPass\n");
      echo("                    To fetch Drupal's versions, this script makes use of github's API.\n");
      echo("                    Autenticated requests receive a greater rate limit.\n");
      echo("  -h, --help        This help,\n");
      echo("\n");
      exit;
  }

  $validVersionRegexp = "/^\d((\.)?\d+)*(\.(0+-alpha(-)?\d+|0+-beta(-)?\d+|0+-rc(-)?\d+|x))?$/";

  if (isset($options['minVersion'])) {
      if (!preg_match($validVersionRegexp, $options['minVersion'])) {
        echo("Invalid version ".$options['minVersion']."\n");
        echo("\n");
        exit;
      }
  } else {
    $options['minVersion'] = false;
  }

  if (isset($options['maxVersion'])) {
      if (!preg_match($validVersionRegexp, $options['maxVersion'])) {
        echo("Invalid version ".$options['minVersion']."\n");
        echo("\n");
        exit;
      }
  } else {
    $options['maxVersion'] = false;
  }

  if ($options['minVersion'] && $options['maxVersion'] && 
      $options['minVersion'] > $options['maxVersion']) {
      echo($options['minVersion']." is greater than ".$options['maxVersion']."\n");
      echo("\n");
      exit; 
  }

  if (isset($options['githubUser']) && isset($options['githubPass'])) {
    $password = "";
    // Check if passwrod was provided.
    if (isset($_SERVER['argv'])) {
      foreach ($_SERVER['argv'] as $i => $arg) {
        if ($arg == "--githubPass") {
          break;
        }
      }
      if (isset($_SERVER['argv'][$i+1])) {

        $nextArgument = $_SERVER['argv'][$i+1];
        $password     = $nextArgument;

        if (preg_match("/^-(-)?/", $nextArgument)) {
          $nextArgument = preg_replace("/^-(-)?/", '', $nextArgument);
          if (isset($options[$nextArgument])) {
            // $nextArgument is actually another option, not a password
            $password = "";
          }
        }
      }
    }
    // If no password was provided, ask the user.
    if (!$password) {
      // Hide characters when typing password.
      system("stty -echo");
      echo("Please, type password: ");
      // Get rid of newline when reading stdin.
      $password = substr(fgets(STDIN), 0, -1);
      system("stty echo");
      echo("\n");
    }
    $options['githubPass'] = $password;
  }

  if (!isset($options['githubUser'])) {
    $options['githubUser'] = false;
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

