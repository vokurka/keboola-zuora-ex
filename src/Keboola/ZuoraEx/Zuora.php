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

    foreach (array('start_date', 'end_date') as $dateId)
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
        'base_url' => "https://www.zuora.com/apps", 
        'headers' => array(
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
        ), 
        'username' => $this->config['username'],
        'password' => $this->config['password'],
    ));

    $this->api->register_decoder('json', 
    create_function('$a', "return json_decode(\$a, TRUE);"));
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
      throw new Exception("Sending queries failed - maybe invalid query? Message: ".$result['message']);
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

    $jsonLoad = json_encode(array(
      "format" => "csv",
      "version" => "1.1",
      "name" => "reports",
      "queries"  => $queries,
    ));

    $result = $this->api->post("/api/batch-query/", $jsonLoad);

    return $result->decode_response();
  }

  private function getJobStatus($id)
  {
    $result = $this->api->get("/api/batch-query/jobs/".$id);

    $result = $result->decode_response();

    return $result;
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
      $username = str_replace('@', '%40', $this->config['username']);
      $password = str_replace('@', '%40', $this->config['password']);

      if (!$remote = fopen("https://{$username}:{$password}@www.zuora.com/apps/api/file/{$b['fileId']}", 'r'))
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