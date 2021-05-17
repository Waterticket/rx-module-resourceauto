<?php

/**
 * Resource 자동 갱신
 * 
 * Copyright (c) Waterticket
 * 
 * Generated with https://www.poesis.org/tools/modulegen/
 */
class ResourceautoView extends Resourceauto
{
	/**
	 * 초기화
	 */
	public function init()
	{
		// 스킨 경로 지정
		$this->setTemplatePath($this->module_path . 'skins/default');
	}
	
	/**
	 * 메인 화면 예제
	 */
	public function dispResourceautoRegisterPackage()
	{
		$logged_info = Context::get('logged_info');
		if(empty($logged_info->member_srl))
		{
			return $this->createObject(-1, '자료 등록은 로그인이 필요합니다');
		}

		// 스킨 파일명 지정
		$oDocumentModel = &getModel('document');
		Context::set('categories', $oDocumentModel->getCategoryList(135));
		$this->setTemplateFile('register');
	}
}
