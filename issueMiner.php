#!/usr/bin/php
<?php

require_once('utils.inc');
require_once('modules.inc');

/**
 * Finds all the stable versions between (inclusive), and return an associative
 * array information about them.
 * 
 * @param string $minVersion
 * @param string $maxVersion
 * @param mixed  $username
 * @param string $password
 * @return array The array with each version. Each element is a major release,
 *               in which each element in turn is a minor release. The key is
 *               always the versions's name.
 */
function findVersions($minVersion = false, $maxVersion = false, $username = false, $password = '') {

  global $ch;
  global $now;
  $versions = array();

  $response = makeGitHubApiRequest($ch,
                                   'https://api.github.com/repos/drupal/drupal/git/refs/tags',
                                   $username,
                                   $password);

  if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
    list($headers, $content) = explode("\r\n\r\n", $response, 2);

    $tags = json_decode($content);

    foreach ($tags as $tag) {

      $versionName = str_replace('refs/tags/', '', $tag->ref);
      if ((!$minVersion || compareVersions($minVersion, $versionName) <= 0) && 
          (!$maxVersion || compareVersions($versionName, $maxVersion) <= 0)) {

        $majorVersion = substr($versionName, 0, strpos($versionName, '.'));

        $response = makeGitHubApiRequest($ch,
                                         $tag->object->url,
                                         $username,
                                         $password);
        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
          list($headers, $content) = explode("\r\n\r\n", $response, 2);

          if ($tag->object->type == "tag") {
            $tag = json_decode($content);
            $response = makeGitHubApiRequest($ch,
                                             $tag->object->url,
                                             $username,
                                             $password);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) == 200) {
              list($headers, $content) = explode("\r\n\r\n", $response, 2);
            } else {
              echo("Problem requesting version $versionName at ".$tag->object->url."\n");
              continue;
            }
          }

          $commit = json_decode($content);
          $versionTimestamp = strtotime($commit->committer->date, $now);

          echo("Adding version $versionName\n");
          $version = array(
            'name'      => $versionName,
            'timestamp' => $versionTimestamp,
          );
          if ($majorVersion) {
            $versions[$majorVersion.".x"][$versionName] = $version;
          } else {
            $versions[$versionName] = $version;
          }
        } else {
          echo("Problem requesting version $versionName at ".$tag->object->url."\n");
        }
      }
      
    }
  }

  return $versions;

}

function parseIssue(DOMDocument $issuePage, array $versions, array &$modulesErrors) {

  global $now;

  $xpath = new DOMXpath($issuePage);
  $mainContainerDiv = $issuePage->getElementById('block-system-main');
  $metadataContainerDiv = $issuePage->getElementById('block-project-issue-issue-metadata');

  $pubTimestamp = $xpath->query(
    "div/div[@class='content']/div/div[@class='submitted']/time",
    $mainContainerDiv
  );
  $pubTimestamp = $pubTimestamp->item(0);
  $pubTimestamp = intval($pubTimestamp->getAttribute('datetime'));

  $status = $xpath->query(
    "div/div[@class='content']/div[contains(@class,'field-name-field-issue-status')]/div/div",
    $metadataContainerDiv
  );
  $status = $status->item(0);
  $status = $status->nodeValue;

  $module = $xpath->query(
    "div/div[@class='content']/div[contains(@class,'field-name-field-issue-component')]/div/div",
    $metadataContainerDiv
  );
  $module = $module->item(0);
  $module = $module->nodeValue;

  $priority = $xpath->query(
    "div/div[@class='content']/div[contains(@class,'field-name-field-issue-priority')]/div[@class='field-items']/div",
    $metadataContainerDiv
  );
  $priority = $priority->item(0);
  $priority = $priority->nodeValue;

  // Get the closure timestamp of the bug, if it is closed. Otherwise, simply
  // consider the closure timestamp as now, for simplifing comparisions later.
  $closureTimestamp = $now;
  if ((strpos($status, 'Closed') === 0) || (strpos($status, 'Fixed') === 0)) {
    $comments = $xpath->query(
      "div/div[@class='content']/section/div[contains(@class,'comment') and ./div[@class='content']/div/div/div/table/tbody/tr/td[contains(normalize-space(text()), \"".$status."\")]]",
      $mainContainerDiv
    );
    foreach ($comments as $comment) {
      $closureTimestamp = $xpath->query(
        "div[@class='submitted']/time",
        $comment
      );
      $closureTimestamp = $closureTimestamp->item(0);
      $closureTimestamp = strtotime($closureTimestamp->getAttribute('datetime'), $now);
    }
  }

  // Estimate a set of versions in which this issue was present. For that, we
  // get the maximum and the minimum versions (for each branch) ever attributed
  // for this issue, and all the versions in between.
  $initialVersions = array_fill_keys(array_keys($versions), false);
  $finalVersions   = array_fill_keys(array_keys($versions), false);

  $issueVersion = $xpath->query(
    "div/div[@class='content']/div[contains(@class,'field-name-field-issue-version')]/div/div",
    $metadataContainerDiv
  );
  if ($issueVersion->length) {
    $issueVersion = $issueVersion->item(0);
    $issueVersion = $issueVersion->nodeValue;
    if (isVersion($issueVersion)) {
      $majorVersion = substr($issueVersion, 0, strpos($issueVersion, '.')).".x";

      if (isset($initialVersions[$majorVersion])) {
        $initialVersions[$majorVersion] = $issueVersion;
      }
      if (isset($finalVersions[$majorVersion])) {
        $finalVersions[$majorVersion] = $issueVersion;
      }
    }
  }

  $versionTransitions = $xpath->query(
    "div/div[@class='content']/section/div[contains(@class,'comment')]/div[@class='content']/div/div/div/table/tbody/tr[./td[contains(normalize-space(text()), \"Version:\")]]",
    $mainContainerDiv
  );
  foreach ($versionTransitions as $versionTransition) {
    foreach ($versionTransition->childNodes as $versionNode) {
      if ($versionNode->nodeValue && 
          $versionNode->nodeType == XML_ELEMENT_NODE &&
          ($versionNode->getAttribute('class') == 'nodechanges-old' ||
           $versionNode->getAttribute('class') == 'nodechanges-new')
      ) {

        $version = str_replace('» ', '', $versionNode->nodeValue);
        if (isVersion($version)) {
          $majorVersion = substr($version, 0, strpos($version, '.')).".x";

          // If we were asked to analise such branch
          if (isset($initialVersions[$majorVersion])) {
            $currentInitialVersion = $initialVersions[$majorVersion];

            // If there is no initial version or the one found is
            // lesser, update the initial version for that branch
            if (!$currentInitialVersion || compareVersions($version, $currentInitialVersion) < 0) {
              $initialVersions[$majorVersion] = $version;
            }
          }

          // If we were asked to analise such branch
          if (isset($finalVersions[$majorVersion])) {
            $currentFinalVersion = $finalVersions[$majorVersion];

            // If there is no final version or the one found is
            // greater, update the final version for that branch
            if (!$currentFinalVersion || compareVersions($version, $currentFinalVersion) > 0) {
              $finalVersions[$majorVersion] = $version;
            }
          }
        }
      }
    }
  }

  foreach ($versions as $majorVersionName => $majorVersion) {

    // An issue does not necessarely affect all branchs.
    if ($initialVersions[$majorVersionName] &&
        $finalVersions[$majorVersionName]
    ) {
      $initialVersion = &$initialVersions[$majorVersionName];
      $finalVersion   = &$finalVersions[$majorVersionName];

      // If any boundary version has ended as development (e.g. 7.x-dev),
      // which is not a real version, try to replace it with a real version
      // according to the issue publish time (for initial versions)
      // and closure time (for final versions).
      if (strpos($initialVersion, '.x-dev') !== false || 
          strpos($finalVersion,   '.x-dev') !== false) {

        // Search for the versions released during the time the issue was open.
        $releasedVersions = array_filter(
          $majorVersion,
          function($version) use ($pubTimestamp, $closureTimestamp) {
            return ($pubTimestamp <= $version['timestamp'] && $version['timestamp'] < $closureTimestamp);
          }
        );

        if ($count = count($releasedVersions)) {
          usort($releasedVersions, 'compareVersionsByTimestamp');
          if (strpos($initialVersion, '.x-dev') !== false) {
            $initialVersion = $releasedVersions[0]['name'];
          }
          if (strpos($finalVersion, '.x-dev') !== false) {
            $finalVersion = $releasedVersions[$count-1]['name'];
          }
        }

      }

      // Search for the versions affected by this issue in this branch.
      $affectedVersions = array_filter(
        $majorVersion,
        function($version) use ($initialVersion, $finalVersion) {
          return (compareVersions($initialVersion, $version['name']) <= 0 && 
                  compareVersions($version['name'], $finalVersion)   <= 0);
        }
      );

      foreach($affectedVersions as $versionName => $version) {
        $modulesErrors[$majorVersionName][$module][$versionName][$priority]++;
      }
    }
  }

}

// Parses a few arguments
$options = getopt('h:', array('minVersion:', 'maxVersion:', 'githubUser:', 'githubPass', 'help'));

validateArguments($options);

if (!ini_get('date.timezone')) {
  // If there is no timezone is set, set one so date functions don't issue warnings.
  date_default_timezone_set('UTC');
}

$now      = time();
$ch       = curl_init();

//include('versions.inc');
$versions = findVersions($options['minVersion'], $options['maxVersion'],
                         $options['githubUser'], $options['githubPass']);

if (!count($versions)) {
  exit("Could not fetch any version from github api.\n");
}

ksort($versions);
$modulesErrors = array_fill_keys(array_keys($versions), null);
foreach ($versions as $majorVersionName => $majorVersion) {
  if (isVersion($majorVersionName)) {
    uksort($majorVersion, "compareVersions");
    $modulesErrors[$majorVersionName] = array_fill_keys(
      array_keys($modules),
      array_fill_keys(
        array_keys($majorVersion),
        array(
            'Critical' => 0,
            'Major'    => 0,
            'Normal'   => 0,
            'Minor'    => 0,
        )
      )
    );
  } else {
    unset($modulesErrors[$majorVersionName]);
  }
}

//echo var_export($versions, true);

curl_setopt($ch, CURLOPT_URL, 'https://www.drupal.org/project/issues/search/drupal'); 
curl_setopt($ch, CURLOPT_HEADER, 0);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERAGENT, "curl");

$content = curl_exec($ch);

$issuesPage = new DomDocument();

@$issuesPage->loadHTML($content);

// Search parameters
$queryString = array();

// Select module.
if (isset($modules)) {
  $componentsElement = $issuesPage->getElementById('edit-component');
  foreach ($componentsElement->childNodes as $componentElement) {
    $component = $componentElement->getAttribute('value');
    if (in_array($component, $modules)) {
     $queryString['component'][] = $component;
    }
  }
}

// Select category.
$categoriesElement = $issuesPage->getElementById('edit-categories');
foreach ($categoriesElement->childNodes as $categoryElement) {
  $category = $categoryElement->nodeValue;
  if ($category == 'Bug report') {
   $queryString['categories'][] = $categoryElement->getAttribute('value');
  }
}

// Select all statuses except for duplicated bugs, non reproducible bugs and bugs working as designed.
$statusesElement = $issuesPage->getElementById('edit-status');
foreach ($statusesElement->childNodes as $statusElement) {
  $status = $statusElement->nodeValue;
  if ($status != 'Closed (duplicate)' && 
      $status != 'Closed (works as designed)' &&
      $status != 'Closed (cannot reproduce)'
  ) {
   $queryString['status'][] = $statusElement->getAttribute('value');
  }
}

$queryString['order'] = 'field_issue_component';
$queryString['sort']  = 'asc';

$queryString = http_build_str($queryString);

curl_setopt($ch, CURLOPT_URL, 'https://www.drupal.org/project/issues/search/drupal?'.$queryString); 


do {

    $content = curl_exec($ch);

    @$issuesPage->loadHTML($content);
    $xpath = new DOMXpath($issuesPage);

    $containerDiv = $xpath->query(
      "//div[@id='block-system-main']/div[@class='block-inner']/div[@class='content']/div[contains(@class,'view')]"
    );
    $containerDiv = $containerDiv->item(0);

    $issueLinks = $xpath->query(
      "div[@class='view-content']/table/tbody/tr/td[contains(@class,'views-field-title')]/a",
      $containerDiv
    );

    if ($issueLinks->length) {
      echo("Should process ".$issueLinks->length." issues.\n");
      foreach($issueLinks as $issueLink) {
        $issueUrl = $issueLink->getAttribute('href');
        if (strpos($issueUrl, 'http') === false) {
          $issueUrl = 'https://www.drupal.org'.$issueUrl;
        }
        curl_setopt($ch, CURLOPT_URL, $issueUrl);
        $content = curl_exec($ch);
         $issuePage = new DomDocument();
        @$issuePage->loadHTML($content);
        echo("\tParsing issue ".$issueUrl."\n");
        parseIssue($issuePage, $versions, $modulesErrors);
      }
    }

    $nextPageLink = $xpath->query(
      "div[@class='item-list']/ul[@class='pager']/li[@class='pager-next']/a",
      $containerDiv
    );

    if ($nextPageLink->length) {
      $nextPageUrl = $nextPageLink->item(0)->getAttribute('href');
      if (strpos($nextPageUrl, 'http') === false) {
        $nextPageUrl = 'https://www.drupal.org'.$nextPageUrl;
      }
      curl_setopt($ch, CURLOPT_URL, $nextPageUrl);
    }

} while ($nextPageLink->length);

// close curl resource to free up system resources
curl_close($ch);

foreach ($versions as $majorVersionName => $majorVersion) {
  $header  = ';'.implode(';;;;', array_keys($majorVersion))."\n";
  $header .= ';'.implode(';', array_fill(0, count($majorVersion), 'Critical;Major;Normal;Minor'))."\n";
  print_string($header);
  foreach ($modulesErrors[$majorVersionName] as $module => $moduleErrors)
  print_results($module, $moduleErrors);
  print_string("\n");
}

