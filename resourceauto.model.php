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
    public function downloadRepoAndInsertFile($owner, $repo, $folder_name, $module_srl, $item_srl)
    {
        $gitClass = new githubRepo();
        $zip_file = $gitClass->downloadRepo($owner, $repo, $folder_name);

        $file_structure = array();
        $file_structure["name"] = $zip_file->file_name;
        $file_structure["type"] = "application/zip";
        $file_structure["tmp_name"] = $zip_file->path;
        $file_structure["error"] = 0;
        $file_structure["size"] = filesize($zip_file->path);

        $oFileController = getController('file');
        $output = $oFileController->insertFile($file_structure, $module_srl, $item_srl, 0, true);

        $file_srl = $output->variables['file_srl'];
        return $file_srl;
    }

    public function insertResourceItem($package_srl, $owner, $repo, $module_name)
    {
        $module_srl = 135;
        // $package_srl = 140;
        // $owner = "Waterticket";
        // $repo = "rx-module-hotopay";
        // $module_name = "hotopay";

        $gitClass = new githubRepo();
        $repo_data = $gitClass->getGithubCommits($owner, $repo);

        $document_srl = $this->insertInfoDocument($package_srl, $repo_data); // 글 등록

        $item_srl = getNextSequence(); // 아이템 srl
        $file_srl = $this->downloadRepoAndInsertFile($owner, $repo, $module_name, $module_srl, $item_srl); // 파일 등록

        $this->insertItem($item_srl, $package_srl, $document_srl, $file_srl, $repo_data);
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
        $doc_args->content = "<p>".$repo_data->message."</p>";
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
}

class githubRepo
{
    public static $github_api_url = "https://api.github.com";
    private static $temp_dir = RX_BASEDIR."files/cache/resourceauto/";

	function getGithubCommits($owner, $repo)
    {
        $sub_url = sprintf("/repos/%s/%s/commits", $owner, $repo);
        $output = FileHandler::getRemoteResource(self::$github_api_url.$sub_url);

        $commits = json_decode($output);
        $recent_commit = $commits[0];

        $commit_sha = $recent_commit->sha;
        $commit_message = $recent_commit->commit->message;
        $date = new DateTime($recent_commit->commit->committer->date);
        $profile = $recent_commit->author->avatar_url;

        $result = new stdClass();
        $result->sha = $commit_sha;
        $result->date = $date->format('Y-m-d');
        $result->message = $commit_message;
        $result->profile = $profile;
        return $result;
    }

    /*
    * @return downloaded file path
    */
    function getGithubRepoFile($owner, $repo, $commit = 'master', $ext = 'tar')
    {
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

        return self::$temp_dir.$filename;
    }

    function renamedir($filedir, $newname)
    {
        shell_exec(sprintf("mv '%s' '%s'", $filedir, self::$temp_dir.$newname));
        return self::$temp_dir.$newname;
    }

    public function downloadRepo($owner, $repo, $folder_name)
    {
        $repo_data = $this->getGithubCommits($owner, $repo);
        $file_name = $this->getGithubRepoFile($owner, $repo, $repo_data->sha);
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