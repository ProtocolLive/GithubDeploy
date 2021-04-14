<?php
// 2021.04.13.01
// Protocol Corporation Ltda.
// https://github.com/ProtocolLive/GithubDeploy/

class GithubDeploy{
  private string $Token;
  private array $Json = [];
  private array $Errors = [];
  private bool $Log = true;

  private function Error(int $errno, string $errstr, string $errfile, int $errline, array $errcontext):void{
    $this->Errors[] = [$errno, $errstr, $errfile, $errline];
    if(ini_get('display_errors')):
      echo '<pre>';
      debug_print_backtrace();
      echo '</pre>';
    endif;
    if($this->Log):
      $this->MkDir(__DIR__ . '/logs');
      file_put_contents(
        __DIR__ . '/logs/' . date('Y-m-d-H-i-s') . '.log',
        json_encode($this->Errors, JSON_PRETTY_PRINT)
      );
    endif;
    die();
  }

  private function MkDir(string $Dir, int $Perm = 0755, bool $Recursive = true):void{
    if(is_dir($Dir) === false):
      mkdir($Dir, $Perm, $Recursive);
    endif;
  }

  /**
   * @return string|false
   */
  private function FileGet(string $File){
    $header = [
      'http' => [
        'method' => 'GET',
        'header' => 'User-Agent: Protocol GithubDeploy'
      ]
    ];
    if($this->Token !== ''):
      $header['http']['header'] .= "\nAuthorization: token " . $this->Token;
    endif;
    $return = file_get_contents($File, false, stream_context_create($header));
    return $return;
  }

  private function Comment(string $Url, string $Data):bool{
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
    return true;
  }

  private function JsonSave():void{
    file_put_contents(__DIR__ . '/GithubDeploy.json', json_encode($this->Json, JSON_PRETTY_PRINT));
  }

  private function JsonLoad():void{
    $temp = __DIR__ . '/GithubDeploy.json';
    if(file_exists($temp)):
      $temp = file_get_contents($temp);
      $this->Json = json_decode($temp, true);
    endif;
  }

  private function JsonSet(string $User, string $Repository, string $Folder, string $Field, string $Value):void{
    $this->Json[$User][$Repository][$Folder][$Field] = $Value;
  }

  /**
   * @return string|false
   */
  private function JsonGet(string $User, string $Repository, string $Folder, string $Field){
    return $this->Json[$User][$Repository][$Folder][$Field] ?? false;
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
        $this->MkDir($Folder);
        file_put_contents($Folder . '/' . $item['name'], $temp);
      elseif($item['type'] === 'dir'):
        $this->MkDir($Folder . '/' . $item['name']);
        $this->DeployDir($item['url'], $Folder . '/' . $item['name']);
      endif;
    endforeach;
  }

  private function DeployCommit(string $Commit, string $Folder):void{
    $Remote = $this->FileGet($Commit);
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
        unlink($Folder . '/' . $item['filename']);
      elseif($item['status'] === 'renamed'):
        rename($Folder . '/' . $item['previous_filename'], $Folder . '/' . $item['filename']);
      endif;
    endforeach;
  }

  public function __construct(string $Token = '', bool $Log = true){
    if(extension_loaded('openssl') === false):
      return false;
    endif;
    $this->Token = $Token;
    $this->Log = $Log;
  }

  public function Deploy(string $User, string $Repository, string $Folder, bool $CommentInCommit = false):bool{
    set_error_handler([$this, 'Error']);
    $return = false;
    $this->JsonLoad();
    $temp = 'https://api.github.com/repos/' . $User . '/' . $Repository . '/commits';
    $Remote = $this->FileGet($temp);
    $Remote = json_decode($Remote, true);
    if($this->JsonGet($User, $Repository, $Folder, 'sha')):
      $this->JsonSet($User, $Repository, $Folder, 'Checked', date('Y-m-d H:i:s'));
      $Commits = [];
      foreach($Remote as $commit):
        if($commit['sha'] !== $this->JsonGet($User, $Repository, $Folder, 'sha')):
          $Commits[] = [
            'url' => $commit['url'],
            'comment' => $commit['comments_url']
          ];
        else:
          break;
        endif;
      endforeach;
      $Commits = array_reverse($Commits);
      foreach($Commits as $commit):
        $this->DeployCommit($Remote[0]['url'], $Folder);
        $this->JsonSet($User, $Repository, $Folder, 'sha', $Remote[0]['sha']);
        $this->JsonSet($User, $Repository, $Folder, 'LastRun', date('Y-m-d H:i:s'));
        if($CommentInCommit):
          $this->Comment(
            $commit['comment'],
            'Commit deployed at ' . date('Y-m-d H:i:s') . ' (' . ini_get('date.timezone') . ')'
          );
        endif;
        $return = true;
      endforeach;
    else:
      $this->DeployDir('https://api.github.com/repos/' . $User . '/' . $Repository . '/contents', $Folder);
      $this->JsonSet($User, $Repository, $Folder, 'sha', $Remote[0]['sha']);
      $this->JsonSet($User, $Repository, $Folder, 'LastRun', date('Y-m-d H:i:s'));
      $this->JsonSet($User, $Repository, $Folder, 'Checked', date('Y-m-d H:i:s'));
      if($CommentInCommit):
        $this->Comment(
          $Remote[0]['comments_url'],
          'Repository deployed at ' . date('Y-m-d H:i:s') . ' (' . ini_get('date.timezone') . ')'
        );
      endif;
      $return = true;
    endif;
    $this->JsonSave();
    restore_error_handler();
    return $return;
  }

  public function DeployFile(string $User, string $Repository, string $File, string $Folder):bool{
    set_error_handler([$this, 'Error']);
    $return = false;
    $this->JsonLoad();
    $temp = 'https://api.github.com/repos/' . $User . '/' . $Repository . '/contents' . $File;
    $Remote = $this->FileGet($temp);
    $Remote = json_decode($Remote, true);
    $this->JsonSet($User, $Repository . '/' . $File, $Folder, 'Checked', date('Y-m-d H:i:s'));
    if(($this->JsonGet($User, $Repository . '/' . $File, $Folder, 'sha') ?? false) !== $Remote['sha']):
      file_put_contents($Folder . '/' . basename($File), base64_decode($Remote['content']));
      $this->JsonSet($User, $Repository . '/' . $File, $Folder, 'LastRun', date('Y-m-d H:i:s'));
      $this->JsonSet($User, $Repository . '/' . $File, $Folder, 'sha', $Remote['sha']);
      $return = true;
    endif;
    $this->JsonSave();
    restore_error_handler();
    return $return;
  }

  public function Errors():array{
    return $this->Errors;
  }
}