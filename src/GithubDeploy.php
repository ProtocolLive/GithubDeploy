<?php
// 2021.04.17.07
// Protocol Corporation Ltda.
// https://github.com/ProtocolLive/GithubDeploy/

class GithubDeploy{
  private ?string $Token;
  private array $Json = [];
  private int $Dump;

  public const Dump_None = 0;
  public const Dump_Pre = 1;
  public const Dump_Html = 2;

  private string $User;
  private string $Repository;
  private string $Folder;
  private int $Time;

  private function MkDir(string $Dir, int $Perm = 0755, bool $Recursive = true):void{
    if(is_dir($Dir) === false):
      mkdir($Dir, $Perm, $Recursive);
    endif;
  }

  private function FileGet(string $File):?string{
    $header = [
      'http' => [
        'method' => 'GET',
        'header' => 'User-Agent: Protocol GithubDeploy'
      ]
    ];
    if($this->Token !== null):
      $header['http']['header'] .= "\nAuthorization: token " . $this->Token;
    endif;
    $return = file_get_contents($File, false, stream_context_create($header));
    return $return === false ? null : $return;
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

  private function JsonSet(string $File, string $Field, $Value):void{
    $this->Json[$this->User][$this->Repository][$this->Folder][$File][$Field] = $Value;
  }

  private function JsonGet(string $File, string $Field):?string{
    return $this->Json[$this->User][$this->Repository][$this->Folder][$File][$Field] ?? null;
  }

  private function DirDeploy(string $Remote, string $Folder, array &$FilesIgnored = []):void{
    $Remote = $this->FileGet($Remote);
    $Remote = json_decode($Remote, true);
    foreach($Remote as $file):
      if($file['type'] === 'dir'):
        $this->DirDeploy($file['url'], $Folder . '/' . $file['name'], $FilesIgnored);
      else:
        $File = $Folder . '/' . $file['name'];
        $this->JsonSet($File, 'Seen', $this->Time);
        if($this->JsonGet($File, 'Sha') !== $file['sha']):
          if(array_search($file['path'], $FilesIgnored) !== false):
            print 'Ignoring ' . $File;
            if($this->Dump === self::Dump_Pre):
              print "\n";
            elseif($this->Dump === self::Dump_Html):
              print '<br>';
            endif;
          else:
            print 'Deploying ' . $File;
            if($this->Dump === self::Dump_Pre):
              print "\n";
            elseif($this->Dump === self::Dump_Html):
              print '<br>';
            endif;
            $temp = $this->FileGet($file['url']);
            $temp = json_decode($temp, true);
            $temp = base64_decode($temp['content']);
            $this->MkDir($Folder);
            file_put_contents($File, $temp);
            $this->JsonSet($File, 'Sha', $file['sha']);
            $this->JsonSet($File, 'Deployed', $this->Time);
          endif;
        endif;
      endif;
    endforeach;
  }

  private function DirNormalize():void{
    foreach($this->Json[$this->User][$this->Repository][$this->Folder] as $file => $data):
      if($data['Seen'] !== $this->Time):
        unlink($file);
        $dir = dirname($file);
        $count = count(scandir($dir)) - 2;
        if($count === 0):
          rmdir($dir);
        endif;
        unset($this->Json[$this->User][$this->Repository][$this->Folder][$file]);
        if($this->Dump === self::Dump_Pre):
          print "Delete $file\n";
        elseif($this->Dump === self::Dump_Html):
          print "Delete $file<br>";
        endif;
      endif;
    endforeach;
  }

  public function __construct(?string $Token = null, int $Dump = self::Dump_Pre){
    if(extension_loaded('openssl') === false):
      throw new Exception('Extension OpenSSL not found');
    endif;
    $this->Token = $Token;
    $this->Dump = $Dump;
  }

  public function Deploy(string $User, string $Repository, string $LocalFolder, string $RemoteFolder = '/src', array $FilesIgnored = []):bool{
    $this->User = $User;
    $this->Repository = $Repository;
    $this->Folder = $LocalFolder;
    $this->Time = time();
    $this->JsonLoad();
    $this->DirDeploy('https://api.github.com/repos/' . $User . '/' . $Repository . '/contents' . $RemoteFolder, $LocalFolder, $FilesIgnored);
    $this->DirNormalize();
    $this->JsonSave();
    return true;
  }
}