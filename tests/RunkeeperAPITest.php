<?php

class RunkeeperApiTest extends PHPUnit_Framework_TestCase
{
	public function testCanParseYamlConfiguration()
	{
		$configuration = __DIR__.'/../config/rk-api.sample.yml';
		$runkeeper = new RunKeeperAPI($configuration);
		$configuration = $runkeeper->api_conf;

		$this->assertEquals('https://runkeeper.com/apps/authorize', $configuration->App->auth_url);
	}
}