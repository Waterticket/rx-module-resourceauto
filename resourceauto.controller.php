<?php

/**
 * Resource 자동 갱신
 * 
 * Copyright (c) Waterticket
 * 
 * Generated with https://www.poesis.org/tools/modulegen/
 */
class ResourceautoController extends Resourceauto
{
	public function procResourceautoRegisterPackage()
	{
		$config = $this->getConfig();
		$vars = Context::getRequestVars();
		$oResourceAutoModel = getModel('resourceauto');

		$category_srl = $vars->category_srl;
		$repo_url = $vars->repo_url;
		$title = $vars->title;
		$install_location = $vars->install_location; // './modules/blahblah'

		if(empty($category_srl) || empty($repo_url) || empty($title) || empty($install_location))
		{
			return $this->createObject(-1, '필수 입력값을 채워주세요!');
		}

		$args = new stdClass();
		$args->repo_url = $repo_url;
		$output = executeQuery('resourceauto.getPackageCountByUrl', $args);
		if($output->data->count > 0)
		{
			return $this->createObject(-1, '이미 해당 URL에 해당하는 레포지트리가 등록되어 있습니다!');
		}

		$type = 'unknown';

		$ins_location = explode('/', $install_location);
		switch($ins_location[1])
		{
			case 'modules':
				$type = 'module';
				break;

			case 'addons':
				$type = 'addon';
				break;

			case 'layouts':
				$type = 'layout';
				break;

			case 'm.layouts':
				$type = 'layout';
				break;

			case 'widgets':
				$type = 'widget';
				break;
		}

		$program_name = $ins_location[2];

		$githubClass = new githubRepo($repo_url);
		$repo_data = $githubClass->getGithubRepository();
		$commit_data = $githubClass->getGithubCommits();

		$logged_info = Context::get('logged_info');
		$member_srl = $logged_info->member_srl;

		$package_srl = $oResourceAutoModel->insertPackage($category_srl, $repo_data, $title, $program_name, $install_location, $member_srl);
		$oResourceAutoModel->insertAutoUpdateTable($package_srl, $repo_url, $program_name, substr($commit_data->sha, 0, 6), $type);

		// 다운로드
		$oResourceAutoModel->insertResourceItem($package_srl);

		$this->setMessage('success_registed');
        $this->setRedirectUrl(getNotEncodedUrl('', 'mid', 'download','category_srl', $category_srl, 'package_srl', $package_srl));
	}

	public function procResourceautoUpdatePackage()
	{
		@set_time_limit(0);

		$oResourceAutoModel = getModel('resourceauto');
		$output = executeQueryArray('resourceauto.getAllPackage');
		$updated_package = array();
		foreach($output->data as $inc => $data)
		{
			if($oResourceAutoModel->checkUpdate($data->package_srl))
			{
				array_push($updated_package, $data->package_srl);
				$oResourceAutoModel->insertResourceItem($data->package_srl);
			}
		}

		die(json_encode(array(
			"error" => 0,
			"message" => "success",
			"updated_package" => $updated_package
		)));
	}
}
