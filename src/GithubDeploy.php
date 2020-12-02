<?php
// Protocol Corporation Ltda.
// https://github.com/ProtocolLive/GithubDeploy/
// Version 2020.12.02.00
// Optimized for PHP 7.4

class GithubDeploy{
  private string $Token;
  private array $Json = [];

  private function FileGet(string $File){
    $header = [
      'http' => [
        'method' => 'GET',
        'header' => "User-Agent: Protocol GithubDeploy\r\n"
      ]
    ];
    if($this->Token !== ''):
      $header['http']['header'] .= 'Authorization: token ' . $this->Token;
    endif;
    return file_get_contents($File, false, stream_context_create($header));
  }

  private function JsonSave():void{
    file_put_contents(__DIR__ . '/GithubDeploy.json', json_encode($this->Json, JSON_PRETTY_PRINT));
  }

  private function JsonRead():void{
    $temp = __DIR__ . '/GithubDeploy.json';
    if(file_exists($temp)):
      $temp = file_get_contents($temp);
      $this->Json = json_decode($temp, true);
    endif;
  }

  private function DeployDir(string $Remote, string $Local):void{
    $remote = $this->FileGet($Remote);
    $remote = json_decode($remote, true);
    foreach($remote as $item):
      if($item['type'] === 'file'):
        $temp = $item['url'];
        $temp = $this->FileGet($temp);
        $temp = json_decode($temp, true);
        $temp = base64_decode($temp['content']);
        file_put_contents($Folder . '/' . $item['name'], $temp);
      else:
        @mkdir($Folder . '/' . $item['name'], 0755, true);
        $this->DeployDir($Remote . '/' . $item['name'], $Local . '/' . $item['name']);
      endif;
    endforeach;
  }

  private function DeployAll(string $User, string $Repository, string $Folder):void{
    $this->Json['LastRun'] = time();
    $this->DeployDir(
      'https://api.github.com/repos/' . $User . '/' . $Repository . '/contents',
      $Folder
    );
  }

  private function DeployCommit(string $User, string $Repository, string $Folder, string $Commit):void{
    $Remote = $this->FileGet('https://api.github.com/repos/' . $User . '/' . $Repository . '/commits/' . $Commit);
    $Remote = json_decode($Remote, true);
    $Remote = $Remote['files'];
    foreach($Remote as $item):
      if($item['status'] === 'added' or $item['status'] === 'modified'):
        $temp = $this->FileGet($item['contents_url']);
        $temp = json_decode($temp, true);
        $temp = $temp['content'];
        $temp = base64_decode($temp);
        $name = strrpos($item['filename'], '/');
        $name = substr($item['filename'], 0, $name);
        @mkdir($Folder . '/' . $name, 0755, true);
        file_put_contents($Folder . '/' . $item['filename'], $temp);
      elseif($item['status'] === 'removed'):
        @unlink($Folder . '/' . $item['filename']);
      elseif($item['status'] === 'renamed'):
        rename($Folder . '/' . $item['previous_filename'], $Folder . '/' . $item['filename']);
      endif;
    endforeach;
  }

  public function __construct(string $Token = ''){
    $this->Token = $Token;
  }

  public function Deploy(
    string $User,
    string $Repository,
    string $Folder,
    string $Trunk = 'master'
  ):void{
    $this->JsonRead();
    $Remote = $this->FileGet('https://api.github.com/repos/' . $User . '/' . $Repository . '/commits');
    $Remote = json_decode($Remote, true);
    if(isset($this->Json['Deploys'][$Repository])):
      if($this->Json['Deploys'][$Repository]['sha'] !== $Remote[0]['sha']):
        $this->DeployCommit($User, $Repository, $Folder, $Remote[0]['sha']);
        $this->Json['Deploys'][$Repository]['sha'] !== $Remote[0]['sha'];
      endif;
    else:
      $this->DeployAll($User, $Repository, $Folder);
      $this->Json['Deploys'][$Repository]['sha'] = $Remote[0]['sha'];
    endif;
    $this->JsonSave();
  }
}