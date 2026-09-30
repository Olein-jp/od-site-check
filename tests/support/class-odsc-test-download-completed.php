<?php
/**
 * Download completion signal for integration tests.
 *
 * @package ODSiteCheck
 */

/**
 * Signals that a test download reached the normal request termination point.
 */
final class ODSC_Test_Download_Completed extends RuntimeException {}
