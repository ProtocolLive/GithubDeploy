<?php
// Protocol Corporation Ltda.
// https://github.com/ProtocolLive/GithubDeploy/
// Version 2020.12.02.01
// Optimized for PHP 7.4

class GithubDeploy{
  private string $Token;
  private array $Json = [];
  private array $Errors = [];

  private function Error(int $errno, string $errstr, string $errfile, int $errline, array $errcontext):void{
    $this->Errors[] = [$errno, $errstr, $errfile, $errline, $errcontext];
    if(ini_get('display_errors')):
      echo '<pre>';
      debug_print_backtrace();
      echo '</pre>';
    endif;
  }

  private function MkDir(string $Dir, int $Perm = 0755, bool $Recursive = true):void{
    mkdir($Dir, $Perm, $Recursive);
  }

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
    set_error_handler([$this, 'Error']);
    $return = file_get_contents($File, false, stream_context_create($header));
    restore_error_handler();
    return $return;
  }

  private function Comment(string $Url, string $Data){
    if($this->Token === ''):
      return false;
    endif;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $Url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
      'User-Agent: Protocol GithubDeploy',
      'Authorization: token ' . $this->Token,
      'Accept: application/vnd.github.v3+json'
    ]);
    curl_setopt($curl, CURLOPT_POSTFIELDS, '{"body":"Protocol GithubDeploy: ' . $Data . '"}');
    curl_exec($curl);
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

  private function DeployDir(string $Remote, string $Folder):void{
    $remote = $this->FileGet($Remote);
    $remote = json_decode($remote, true);
    foreach($remote as $item):
      if($item['type'] === 'file'):
        $temp = $item['url'];
        $temp = $this->FileGet($temp);
        $temp = json_decode($temp, true);
        $temp = base64_decode($temp['content']);
        file_put_contents($Folder . '/' . $item['name'], $temp);
      elseif($item['type'] === 'dir'):
        $this->MkDir($Folder . '/' . $item['name']);
        $this->DeployDir($item['url'], $Folder . '/' . $item['name']);
      endif;
    endforeach;
  }

  private function DeployAll(string $User, string $Repository, string $Folder):void{
    $this->Json['LastRun'] = time();
    $this->DeployDir('https://api.github.com/repos/' . $User . '/' . $Repository . '/contents', $Folder);
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
        $this->MkDir($Folder . '/' . $name);
        file_put_contents($Folder . '/' . $item['filename'], $temp);
      elseif($item['status'] === 'removed'):
        @unlink($Folder . '/' . $item['filename']);
      elseif($item['status'] === 'renamed'):
        rename($Folder . '/' . $item['previous_filename'], $Folder . '/' . $item['filename']);
      endif;
    endforeach;
  }

  public function __construct(string $Token = ''){
    if(extension_loaded('openssl') === false):
      return false;
    endif;
    $this->Token = $Token;
  }

  public function Deploy(string $User, string $Repository, string $Folder, string $Trunk = 'master'):void{
    set_error_handler([$this, 'Error']);
    $this->JsonRead();
    $temp = 'https://api.github.com/repos/' . $User . '/' . $Repository . '/commits';
    $Remote = $this->FileGet($temp);
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
    $this->Comment(
      $Remote[0]['comments_url'],
      'Repository deployed at ' . date('Y-m-d H:i:s') . ' (' . ini_get('date.timezone') . ')'
    );
    $this->JsonSave();
    restore_error_handler();
  }

  public function Errors():array{
    return $this->Errors;
  }
}