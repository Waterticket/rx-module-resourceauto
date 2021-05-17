<?php

/**
 * Resource 자동 갱신
 * 
 * Copyright (c) Waterticket
 * 
 * Generated with https://www.poesis.org/tools/modulegen/
 */
class ResourceautoModel extends Resourceauto
{
    //return item_srl
    public function insertResourceItem($package_srl)
    {
        $module_srl = 135;

        $package_data = $this->getPackageFromAutoDB($package_srl);
        $module_name = $package_data->program_name;
        $url = $package_data->repo_url;

        $gitClass = new githubRepo($url);
        $repo_data = $gitClass->getGithubCommits();

        $document_srl = $this->insertInfoDocument($package_srl, $repo_data); // 글 등록

        $item_srl = getNextSequence(); // 아이템 srl
        $file_srl = $this->downloadRepoAndInsertFile($gitClass, $module_name, $module_srl, $item_srl); // 파일 등록

        $this->insertItem($item_srl, $package_srl, $document_srl, $file_srl, $repo_data);
        $this->updateAutoDBVersion($package_srl, substr($repo_data->sha, 0, 6));

        return $item_srl;
    }

    // return package_srl
    public function insertPackage($category_srl, $repo_data, $title, $module_name, $install_path, $member_srl = 203)
    {
        $args = new stdClass();
        $args->package_srl = getNextSequence();
        $args->module_srl = 135;
        $args->member_srl = $member_srl ?: 203;
        $args->list_order = -1*$args->package_srl;
        $args->status = 'accepted';
        $args->comment_count = 0;
        $args->voter = 0;
        $args->voted = 0;
        $args->downloaded = 0;
        $args->regdate = date('YmdHis');
        $args->last_update = date('YmdHis');

        $args->category_srl = $category_srl;
        $args->title = $title;
        $args->license = ($repo_data->license->spdx_id) ?: 'github 참조';
        $args->homepage = $repo_data->html_url;
        $args->description = $repo_data->description;
        $args->path = $install_path;

        $args->latest_item_srl = 0;
        $args->update_order = 0;

        executeQuery('resource.insertPackage', $args);

        return $args->package_srl;
    }

    public function insertAutoUpdateTable($package_srl, $repo_url, $program_name, $version, $type = 'module')
    {
        $args = new stdClass();
        $args->package_srl = $package_srl;
        $args->repo_url = $repo_url;
        $args->program_name = $program_name;
        $args->type = $type;
        $args->version = $version;
        $args->regdate = date('YmdHis');
        executeQuery('resourceauto.insertPackage', $args);
    }

    public function updateAutoDBVersion($package_srl, $version)
    {
        $args = new stdClass();
        $args->package_srl = $package_srl;
        $args->version = $version;
        $args->regdate = date('YmdHis');
        executeQuery('resourceauto.updatePackageVersion', $args);
    }

    public function downloadRepoAndInsertFile($gitClass, $folder_name, $module_srl, $item_srl)
    {
        $zip_file = $gitClass->downloadRepo($folder_name);

        $file_structure = array();
        $file_structure["name"] = $zip_file->file_name;
        $file_structure["type"] = "application/zip";
        $file_structure["tmp_name"] = $zip_file->path;
        $file_structure["error"] = 0;
        $file_structure["size"] = filesize($zip_file->path);

        $oFileController = getController('file');
        $output = $oFileController->insertFile($file_structure, $module_srl, $item_srl, 0, true);

        $this->removeFile($zip_file->path);

        $file_srl = $output->variables['file_srl'];
        return $file_srl;
    }

    public function removeFile($path)
    {
        if(!empty($path))
        {
            shell_exec('rm -rf '.$path);
        }
    }

    public function insertInfoDocument($package_srl, $repo_data)
    {
        $oDocumentController = getController('document');
        $oResourceModel = getModel('resource');
        $selected_package = $oResourceModel->getPackage(135, $package_srl);

        $doc_args = new stdClass();
        $doc_args->document_srl = getNextSequence();
        $doc_args->category_srl = $selected_package->category_srl;
        $doc_args->module_srl = 135;
        $doc_args->content = "<p>업데이트 사항:</p><p>&nbsp;</p><p>".htmlspecialchars($repo_data->message)."</p>";
        $doc_args->title = sprintf('%s ver. %s', $selected_package->title, substr($repo_data->sha, 0, 6));
        $doc_args->list_order = $doc_args->document_srl*-1;
        $doc_args->tags = Context::get('tag');
        $doc_args->allow_comment = 'Y';
        $doc_args->commentStatus = 'ALLOW';
        $output = $oDocumentController->insertDocument($doc_args);

        return $doc_args->document_srl;
    }

    public function insertItem($item_srl, $package_srl, $document_srl, $file_srl, $repo_data)
    {
        $args = new stdClass();
        $args->module_srl = 135;
        $args->package_srl = $package_srl;
        $args->item_srl = $item_srl;
        $args->document_srl = $document_srl;
        $args->version = substr($repo_data->sha, 0, 6);
        $args->file_srl = $file_srl;
        $args->screenshot_url = $repo_data->profile;
        $args->voter = 0;
        $args->voted = 0;
        $args->regdate = date('YmdHis');
        $args->list_order = $args->item_srl*-1;
        executeQuery('resource.insertItem', $args);
        executeQuery('resource.updateItemFile', $args);

        $args->latest_item_srl = $args->item_srl;
        $args->last_update = $args->regdate;
        $args->update_order = $args->item_srl*-1;
        executeQuery('resource.updatePackage', $args);
    }

    public function getPackageFromAutoDB($package_srl)
    {
        $args = new stdClass();
        $args->package_srl = $package_srl;
        $output = executeQuery('resourceauto.getPackage', $args);
        return $output->data;
    }

    public function updateCheck($package_srl)
    {
        $package_data = $this->getPackageFromAutoDB($package_srl);
        $gitClass = new githubRepo($package_data->repo_url);
        $repo_data = $gitClass->getGithubCommits();

        $version = substr($repo_data->sha, 0, 6);

        return (strcmp($version, $package_data->version) !== 0);
    }
}

class githubRepo
{
    public static $github_api_url = "https://api.github.com";
    private static $temp_dir = RX_BASEDIR."files/cache/resourceauto/";

    public $recent_commit = null;
    public $repository_data = null;

    public $url;
    public $owner;
    public $repo;

    function __construct($url)
    {
        $this->githubUrlParser($url);
    }

    function githubUrlParser($url)
    {
        $this->url = $url;
        preg_match('/https\:\/\/github.com\/(.*?)\/(.*)/', $url, $output_arr);
        
        $this->owner = $output_arr[1];
        $this->repo = basename($output_arr[2], '.git');
    }

    function getGithubRepository()
    {
        $owner = $this->owner;
        $repo = $this->repo;

        if($this->repository_data == null)
        {
            $sub_url = sprintf("/repos/%s/%s", $owner, $repo);
            $output = FileHandler::getRemoteResource(self::$github_api_url.$sub_url);

            $this->repository_data = json_decode($output);
        }
        return $this->repository_data;
    }

	function getGithubCommits()
    {
        $owner = $this->owner;
        $repo = $this->repo;

        if($recent_commit == null)
        {
            $sub_url = sprintf("/repos/%s/%s/commits", $owner, $repo);
            $output = FileHandler::getRemoteResource(self::$github_api_url.$sub_url);

            $commits = json_decode($output);
            $this->recent_commit = $commits[0];
        }

        $commit_sha = $this->recent_commit->sha;
        $commit_message = $this->recent_commit->commit->message;
        $date = new DateTime($this->recent_commit->commit->committer->date);
        $profile = $this->recent_commit->author->avatar_url;

        $result = new stdClass();
        $result->sha = $commit_sha;
        $result->date = $date->format('Y-m-d');
        $result->message = $commit_message;
        $result->profile = $profile;
        $result->url = $this->url;
        return $result;
    }

    /*
    * @return downloaded file path
    */
    function getGithubRepoFile($commit = 'master', $ext = 'tar')
    {
        $owner = $this->owner;
        $repo = $this->repo;

        if(!in_array($ext, array('tar','zip'))) return false;

        @set_time_limit(0);
        $sub_url = sprintf("/repos/%s/%s/%sball/%s", $owner, $repo, $ext, $commit);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::$github_api_url.$sub_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'HotoRepo');
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = curl_exec($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header = substr($response, 0, $header_size);
        $body = substr($response, $header_size);
        if($httpcode != 200) return false;

        $filename = $this->getFilename($header);
        if($filename === false) $filename='xe.tar';

        FileHandler::writeFile(self::$temp_dir.$filename, $body);

        return self::$temp_dir.$filename;
    }

    function getFilename($header) {
        if (preg_match('/content-disposition:.*?filename="(.+?)"/', $header, $matches)) {
            return $matches[1];
        }
        if (preg_match('/content-disposition:.*?filename=([^; ]+)\r\n/', $header, $matches)) {
            return rawurldecode($matches[1]);
        }
        return false;
    }

    // return extracted directory
    function depackTargz($filedir)
    {
        $filename = basename($filedir, '.tar.gz');
        shell_exec(sprintf("cd '%s' && tar -zxvf '%s.tar.gz' && rm -rf '%s.tar.gz'", self::$temp_dir, $filename, $filename));

        $commit_data = $this->getGithubCommits();
        $version = substr($commit_data->sha, 0, 7);
        $dir_name = $this->owner.'-'.$this->repo.'-'.$version;

        return self::$temp_dir.$dir_name;
    }

    function renamedir($filedir, $newname)
    {
        shell_exec(sprintf("mv '%s' '%s'", $filedir, self::$temp_dir.$newname));
        return self::$temp_dir.$newname;
    }

    public function downloadRepo($folder_name)
    {
        if(empty($this->owner) || empty($this->repo)) return false;

        $repo_data = $this->getGithubCommits();
        $file_name = $this->getGithubRepoFile($repo_data->sha);
        $dir = $this->depackTargz($file_name);
        $dir = $this->renamedir($dir, $folder_name);

        $version = substr($repo_data->sha, 0, 6);
        $date = $repo_data->date;
        shell_exec(sprintf("cd %sfiles/resourceauto && ./changeModuleVersion.sh '%s' '%s'", RX_BASEDIR, $dir, $version));
        shell_exec(sprintf("cd %sfiles/resourceauto && ./changeModuleUpdateDate.sh '%s' '%s'", RX_BASEDIR, $dir, $date));
        shell_exec(sprintf("cd '%s' && rm -rf conf/info2.xml", $dir));

        shell_exec(sprintf("cd '%s' && zip '%s-%s.zip' -r './%s' && rm -rf '%s'", self::$temp_dir, $folder_name, $version, $folder_name, $folder_name));
        $zip_file = new stdClass();
        $zip_file->path = sprintf("%s%s-%s.zip", self::$temp_dir, $folder_name, $version);
        $zip_file->file_name = sprintf("%s-%s.zip", $folder_name, $version);
        $zip_file->file_name_without_ext = sprintf("%s-%s", $folder_name, $version);

        return $zip_file;
    }
}