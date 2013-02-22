#!/usr/bin/env php
<?php

//execute a command and check for return values
function setup_exec($cmd, $return_out=false) {
  $ret=0;
  $out=array();
  $cmd.=" 2>&1";
  printf("Launching %s\n",$cmd);
  exec($cmd,$out,$ret);
  if($ret!=0) {
    echo implode("\n",$out);
    printf("'%s' failed. Aborting install.\n",$cmd);
    exit(1);
  }
  if($return_out)
    return $out;
}

//default config
$conf=array("outfile"=>"../php-ftpfs-%d.tar.gz","tag"=>"HEAD","create-tag"=>false);

//init getopt
$shortopts="h";
$longopts=array("outfile::","tag::","create-tag","help");
$options=getopt($shortopts,$longopts);

//help
if(isset($options["h"]) || isset($options["help"])) {
  fprintf(STDERR, "%1\$s
Marco Schuster <marco@m-s-d.eu>

cURL FTP-backed FUSE virtual filesystem - Release script

Usage: %2\$s [options]

Options:
    -h --help                 this help
    --outfile=s               output file. Defaults to %3\$s. %%d is the tag name.
    --tag=s                   tag name (or branch) of the snapshot. Defaults to
                              %4\$s. Must exist, unless --create-tag is given.
                              Output format always will be .tar.gz
    --create-tag              create the tag out of current HEAD
    

","php-ftpfs setup",$argv[0],$conf["outfile"],$conf["tag"]);
  exit(1);
}

//update config with options
if(isset($options["outfile"]) && !is_array($options["outfile"]))
  $conf["outfile"]=$options["outfile"];
if(isset($options["tag"]) && !is_array($options["tag"]))
  $conf["tag"]=$options["tag"];
if(isset($options["create-tag"]))
  $conf["create-tag"]=true;

//check if these are paths, convert them to paths if not
$scriptloc=realpath(dirname(__FILE__))."/";
if(substr($conf["outfile"],0,1)!="/") //relative path
  $conf["outfile"]=$scriptloc.$conf["outfile"];

printf("Creating a snapshot file in %s for branch/tag %s. Continue ([y]/n)? ",$conf["outfile"],$conf["tag"]);
$in=strtolower(fgetc(STDIN));
if($in!="y" && $in!="")
  exit(1);

//prepare temporary directory
$tmploc="/tmp/php-ftpfs-release/";
if(is_dir($tmploc)) {
  printf("Removing old temp data\n");
  setup_exec("rm -rf $tmploc");
}

//create the tag
if($conf["create-tag"])
  setup_exec("cd $scriptloc && git tag -a ".escapeshellarg($conf["tag"])." -m \"release-script creating tag\"");

//clone the repository and clean it up
//setup_exec("cd $scriptloc && git clone --no-hardlinks $scriptloc $tmploc");
setup_exec("cd / && cp -R $scriptloc $tmploc");
setup_exec("cd $tmploc && git clean -f -d -x -n");
setup_exec("cd $tmploc && git reset --hard HEAD");

//check out the tag, branch or commit id
setup_exec("cd $tmploc && git checkout ".escapeshellarg($conf["tag"]));

//get the submodules
if(!is_file($tmploc."php-fuse/README") || !is_file($tmploc."php-src/README.md")) {
  printf("Needing to fetch stuff from git, this may take a while.\n");
  setup_exec("cd $tmploc && git submodule init");
  setup_exec("cd $tmploc && git submodule update");
}

//clean up from builds
if(is_file($tmploc."php-src/Makefile"))
  setup_exec("cd ${tmploc}php-src && make distclean");
if(is_file($tmploc."php-fuse/Makefile"))
  setup_exec("cd ${tmploc}php-fuse && make distclean");

//get the tag description
$tagdesc=setup_exec("cd $tmploc && git describe --tags --always",true); $tagdesc=$tagdesc[0];
printf("Tag description is '%s'\n",$tagdesc);
$conf["outfile"]=str_replace("%d",$tagdesc,$conf["outfile"]);

//remove unneeded directories to clean up space
//git history
setup_exec("cd $tmploc && rm -rf .git");
//php tests
setup_exec("cd $tmploc && rm -rf `find php-src -type d -name \"tests\"`");
//unneeded SAPIs
setup_exec("cd ${tmploc}php-src && find sapi/* -maxdepth 0 -type d ! -name cli -exec rm -rf {} +");
//all exts except required ones
setup_exec("cd ${tmploc}php-src && find ext/* -maxdepth 0 -type d ! -name standard ! -name date ! -name curl ! -name posix ! -name filter ! -name ereg ! -name pcre ! -name reflection ! -name spl ! -name session -exec rm -rf {} +");
//other platforms, utilities
setup_exec("cd ${tmploc}php-src && find . -maxdepth 1 -type d \\( -name travis -or -name pear -or -name win32 -or -name autom4te.cache -or -name netware \\) -exec rm -rf {} +");

//write version identifier
$buf=file_get_contents("${tmploc}/ftpfs/ftpfs.php");
$buf=preg_replace('@git-\\$Id\\$@isU',"git-$tagdesc",$buf);
$buf=preg_replace('@git-\\$Id(.*)\\$@isU',"git-$tagdesc",$buf);
$fp=fopen("${tmploc}/ftpfs/ftpfs.php","w");
fwrite($fp,$buf);
fclose($fp);

//pack the whole thing together
setup_exec("cd / && tar -czf ".escapeshellarg($conf["outfile"])." $tmploc");

printf("Finished output file now in %s\n",$conf["outfile"]);
