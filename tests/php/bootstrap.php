<?php
/**
 * PHPUnit bootstrap for the dependency-free crypto layer.
 *
 * These classes do not need WordPress, so they are tested standalone.
 *
 * @package MetaMask_Login
 */

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__, 2 ) . '/includes/utils/eth-sign-verify.php';
