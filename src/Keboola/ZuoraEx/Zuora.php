<?php

class Zuora
{
  private $api;
  private $config;
  private $destination;
  private $mandatoryConfigColumns = array(
    'bucket', 
    'username', 
    'password',
    'project',
    'start_date',
    'end_date',
    'queries',
  );

  public function __construct($config, $destination)
  {
    date_default_timezone_set('UTC');
    $this->destination = $destination;

    foreach ($this->mandatoryConfigColumns as $c)
    {
      if (!isset($config[$c])) 
      {
        throw new Exception("Mandatory column '{$c}' not found or empty.");
      }

      $this->config[$c] = $config[$c];
    }

    foreach (array('date_from', 'date_to') as $dateId)
    {
      $timestamp = strtotime($this->config[$dateId]);

      if ($timestamp === FALSE)
      {
        throw new Exception("Invalid time value in field ".$dateId);
      }

      $dateTime = new DateTime();
      $dateTime->setTimestamp($timestamp);

      $this->config[$dateId] = $dateTime->format('Y-m-d');
    }

    if (!is_array($this->config['queries']))
    {
      throw new Exception("You have to put some queries in queries list.");
    }

    // API initialization
    $this->api = new RestClient(array(
        'base_url' => "https://www.zuora.com/apps/api/", 
        'format' => "json", 
        'headers' => array('Accept' => 'application/json'), 
        'username' => $this->config['username'],
        'password' => $this->config['password'],
    ));
  }

  private function logMessage($message)
  {
    echo($message."\n");
  }

  public function run()
  {
    // Send queries and check if JSON was valid (id is not null)
    $result = $this->sendQueries();

    if (empty($result['id']))
    {
      throw new Exception("Sending queries failed - maybe invalid format?");
    }

    // Wait till its done
    $counter = 0;
    do
    {
      sleep(2);
      $status = $this->getJobStatus($result['id']);
      $counter++;
    } while (!in_array($status['status'], array('completed', 'error', 'aborted')) && $counter < 1800);

    // Check what is the result status
    if (!empty($status['status']))
    {
      switch($status['status'])
      {
        case 'completed':
          $this->logMessage("Job completed.");
          break;
        
        case 'error':
          throw new Exception("There was an error while sending queries - maybe invalid format?");
          break;
        
        case 'aborted':
          throw new Exception("The job was aborted - maybe invalid query?");
          break;

        default:
          throw new Exception("Unknown job status, aborting.");
      }
    }
    else
    {
      throw new Exception("Getting job status failed, aborting.");
    }

    // Download files
    $this->downloadFiles($status);

    $this->logMessage('Done.');
  }

  private function sendQueries()
  {
    $queries = array();

    foreach ($this->config['queries'] as $name => $query)
    {
      foreach (array('start_date', 'end_date') as $placeholder)
      {
        $query = str_replace('{'.$placeholder.'}', "'".$this->config[$placeholder]."'", $query);
      }

      $queries[] = array(
        'name' => $name,
        'query' => $query,
        'type' => 'zoqlexport',
      );
    }

    $result = $api->post("batch-query", array(
      "format" => "csv",
      "version" => "1.2",
      "name" : "reports",
      "queries"  => $queries,
    ));

    return $result;
  }

  private function getJobStatus($id)
  {
    $result = $api->get("batch-query/jobs/".$id);

    if (isset($result['status']))
    {
      return $result['status'];
    }

    return FALSE;
  }

  private function downloadFiles($status)
  {
    if (empty($status['batches']) || !is_array($status['batches']))
    {
      throw new Exception("Could not find any files to download.");
    }

    // For every file open stream on remote server, open it directly in data out tables folder and strem copy
    foreach ($status['batches'] as $b)
    {
      if (!$remote = fopen("https://www.zuora.com/apps/api/file/{$b['fileId']}", 'r'))
      {
        throw new Exception("Could not download ".$b['name']);
      }

      if (!$local = fopen($this->destination.$this->config['bucket'].'.'.$b['name'], 'w'))
      {
        throw new Exception("Could not open local file for writing for file ".$b['name']);
      }

      stream_copy_to_stream($remote, $local);

      fclose($local);
      fclose($remote);

      $this->logMessage("File ".$b['name']." downloaded.");
    }
  }
}