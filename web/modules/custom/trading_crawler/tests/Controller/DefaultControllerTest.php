<?php

namespace Drupal\trading_crawler\Tests;

//use Drupal\simpletest\WebTestBase;
use Drupal\Tests\BrowserTestBase;

/**
 * Provides automated tests for the trading_crawler module.
 */
class DefaultControllerTest extends BrowserTestBase {


  /**
   * @return string[]
   */
  public static function getInfo() {
    return [
      'name' => "trading_crawler DefaultController's controller functionality",
      'description' => 'Test Unit for module trading_crawler and controller DefaultController.',
      'group' => 'Other',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Tests trading_crawler functionality.
   */
  public function testDefaultController() {
    // Check that the basic functions of module trading_crawler.
    $this->assertEquals(TRUE, TRUE, 'Test Unit Generated via Drupal Console.');
  }

}
