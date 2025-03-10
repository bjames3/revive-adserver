<?php

/*
+---------------------------------------------------------------------------+
| Revive Adserver                                                           |
| http://www.revive-adserver.com                                            |
|                                                                           |
| Copyright: See the COPYRIGHT.txt file.                                    |
| License: GPLv2 or later, see the LICENSE.txt file.                        |
+---------------------------------------------------------------------------+
*/

require_once MAX_PATH . '/lib/OA/Dal/DataGenerator.php';
require_once MAX_PATH . '/lib/OA/Maintenance/Priority/AdServer/Task/GetRequiredAdImpressionsLifetime.php';

Language_Loader::load();

/**
 * A class for performing an integration test of the Prioritisation Engine
 * to ensure that campaign limitations are correctly met when banners in
 * the campaign are blocked via time-based delivery limitations.
 *
 * @package    OpenXMaintenance
 * @subpackage TestSuite
 */
class Maintenance_TestOfMaintenancePriorityAdServerBannerLimitationsTz extends UnitTestCase
{
    public function setUp()
    {
        // Install the openXDeliveryLog plugin
        TestEnv::uninstallPluginPackage('openXDeliveryLimitations', false);
        TestEnv::installPluginPackage('openXDeliveryLimitations', false);
    }

    public function tearDown()
    {
        // Uninstall the openXDeliveryLog plugin
        TestEnv::uninstallPluginPackage('openXDeliveryLimitations', false);
        // Clean up the testing environment
        TestEnv::restoreEnv();
    }

    /**
     * The test to ensure that a campaign with banners that have time-based
     * delivery limitations are correctly prioritised by the MPE, even when
     * there are timezones involved.
     *
     * Test Basis:
     *
     * - One campaign, running from 2008-02-26 to 2008-02-27 (2 days).
     * - Booked impressions of 46,000 impressions (i.e. 1,000 per hour
     *     required on average, with a 2-hour hole).
     * - Two banners in the zone, both with weight one.
     *   - Banner ID 1 has a Time:Date delivery limitation, to only allow
     *       the banner to deliver on 2008-02-26 UTC-1.
     *   - Banner ID 2 has a Time:Date delivery limitation, to only allow
     *       the banner to deliver on 2008-02-27 UTC+1.
     * - The campaign is linked to one constantly delivering zone (at
     *     1,000 impressions per hour).
     *
     * - Run the MPE with an OI of 60 minutes for the two days of the
     *     campaign lifetime, and assume that all required impressions
     *     allocated to the banner(s) are delivered.
     *
     * - During the 2 hour gap, blanks are assumed to be delivered.
     *
     * The expected result of this is that the MPE should allocate 1,000
     * impressions per hour for Banner ID 1 all day on 2008-02-26 before
     * 23:00 UTC, and 1,000 impressions per hour for Banner ID 2 all day
     * on 2008-02-27, starting from 01:00 UTC.
     */
    public function testCampaignWithTimezones()
    {
        $aConf = &$GLOBALS['_MAX']['CONF'];
        $aConf['maintenance']['operationInteval'] = 60;
        $aConf['priority']['useZonePatterning'] = false;
        OA_setTimeZoneUTC();

        $oServiceLocator = OA_ServiceLocator::instance();
        $oServiceLocator->register('now', new Date('2008-02-27'));

        // Prepare the test campaign
        $doCampaigns = OA_Dal::factoryDO('campaigns');
        $doCampaigns->views = 46000;
        $doCampaigns->clicks = -1;
        $doCampaigns->conversions = -1;
        $doCampaigns->activate_time = '2008-02-26 00:00:00';
        $doCampaigns->expire_time = '2008-02-27 23:59:59';
        $doCampaigns->priority = 10;
        $doCampaigns->target_impression = 0;
        $doCampaigns->target_click = 0;
        $doCampaigns->target_conversion = 0;
        $campaignId = DataGenerator::generateOne($doCampaigns);

        // Prepare the first banner
        $doBanners = OA_Dal::factoryDO('banners');
        $doBanners->campaignid = $campaignId;
        $doBanners->active = 't';
        $doBanners->weight = 1;
        $bannerId1 = DataGenerator::generateOne($doBanners);

        $doAcls = OA_Dal::factoryDO('acls');
        $doAcls->bannerid = $bannerId1;
        $doAcls->logical = 'and';
        $doAcls->type = 'deliveryLimitations:Time:Date';
        $doAcls->comparison = '==';
        $doAcls->data = '20080226@Europe/Rome';
        $doAcls->executionorder = 0;
        DataGenerator::generateOne($doAcls);

        // Prepare the second banner
        $doBanners = OA_Dal::factoryDO('banners');
        $doBanners->campaignid = $campaignId;
        $doBanners->active = 't';
        $doBanners->weight = 1;
        $bannerId2 = DataGenerator::generateOne($doBanners);

        $doAcls = OA_Dal::factoryDO('acls');
        $doAcls->bannerid = $bannerId2;
        $doAcls->logical = 'and';
        $doAcls->type = 'deliveryLimitations:Time:Date';
        $doAcls->comparison = '==';
        $doAcls->data = '20080227@Atlantic/Cape_Verde';
        $doAcls->executionorder = 0;
        DataGenerator::generateOne($doAcls);

        // Prepare the zone
        $doZones = OA_Dal::factoryDO('zones');
        $zoneId = DataGenerator::generateOne($doZones);

        // Link the banners to the zone
        $doAd_zone_assoc = OA_Dal::factoryDO('ad_zone_assoc');
        $doAd_zone_assoc->zone_id = $zoneId;
        $doAd_zone_assoc->ad_id = $bannerId1;
        DataGenerator::generateOne($doAd_zone_assoc);

        $doAd_zone_assoc = OA_Dal::factoryDO('ad_zone_assoc');
        $doAd_zone_assoc->zone_id = $zoneId;
        $doAd_zone_assoc->ad_id = $bannerId2;
        DataGenerator::generateOne($doAd_zone_assoc);

        // Run the code to get the required ad impressions over
        // the 48 hour period of the test
        for ($counter = 1; $counter <= 48; $counter++) {
            // Set the "current" date/time that the MPE would be
            // running at for the appropriate hour of the test
            $oNowDate = new Date('2008-02-26 00:00:01');
            $oNowDate->addSeconds(($counter - 1) * SECONDS_PER_HOUR);
            $oServiceLocator->register('now', $oNowDate);

            // Run the code to get the required ad impressions
            $oGetRequiredAdImpressionsLifetime = new OA_Maintenance_Priority_AdServer_Task_GetRequiredAdImpressionsLifetime();
            $oGetRequiredAdImpressionsLifetime->run();

            // Test that 1,000 impressions have been "required" for
            // the appropriate banner
            $query = "SELECT * FROM tmp_ad_required_impression";
            $rsRequiredImpression = DBC::NewRecordSet($query);
            $rsRequiredImpression->find();
            $aRequiredImpressions = $rsRequiredImpression->getAll();
            $this->assertTrue(is_array($aRequiredImpressions), "No array for required impressions SQL result in test hour $counter");
            if ($counter === 24 || $counter === 25) {
                $this->assertEqual(count($aRequiredImpressions), 0, "More than zero rows found for required impressions SQL result in test hour $counter");
                $bannerId = 0;
            } else {
                $this->assertEqual(count($aRequiredImpressions), 1, "Zero or more than one row found for required impressions SQL result in test hour $counter");
                $this->assertTrue(is_array($aRequiredImpressions[0]), "Badly formatted result row for required impressions SQL result in test hour $counter");
                $this->assertEqual(count($aRequiredImpressions[0]), 2, "Badly formatted result row for required impressions SQL result in test hour $counter");
                $bannerId = $aRequiredImpressions[0]['ad_id'];
                if ($counter <= 24) {
                    $this->assertEqual($bannerId, $bannerId1, "Expected required impressions for banner ID $bannerId1 in test hour $counter");
                } else {
                    $this->assertEqual($bannerId, $bannerId2, "Expected required impressions for banner ID $bannerId2 in test hour $counter");
                }
                $impressions = $aRequiredImpressions[0]['required_impressions'];
                $this->assertEqual($impressions, 1000, "Incorrectly requested $impressions impressions instead of 1000 in test hour $counter");
            }

            // Insert the required impressions for the banner into the
            // data_intermediate_ad table, as if the delivery has occurred,
            // so that the next hour's test is based on delivery having happened
            $aDates = OX_OperationInterval::convertDateToOperationIntervalStartAndEndDates($oNowDate);
            $operationIntervalId = OX_OperationInterval::convertDateToOperationIntervalID($oNowDate);
            $doData_intermediate_ad = OA_Dal::factoryDO('data_intermediate_ad');
            $doData_intermediate_ad->day = $aDates['start']->format('%Y-%m-%d');
            $doData_intermediate_ad->hour = $aDates['start']->format('%H');
            $doData_intermediate_ad->operation_interval = $aConf['maintenance']['operationInteval'];
            $doData_intermediate_ad->operation_interval_id = $operationIntervalId;
            $doData_intermediate_ad->interval_start = $aDates['start']->format('%Y-%m-%d %H:%M:%S');
            $doData_intermediate_ad->interval_end = $aDates['end']->format('%Y-%m-%d %H:%M:%S');
            $doData_intermediate_ad->ad_id = $bannerId;
            $doData_intermediate_ad->zone_id = $zoneId;
            $doData_intermediate_ad->requests = $impressions;
            $doData_intermediate_ad->impressions = $impressions;
            $doData_intermediate_ad->clicks = 0;
            $doData_intermediate_ad->conversions = 0;
            DataGenerator::generateOne($doData_intermediate_ad);

            // Drop the temporary table that is used to store the
            // required impressions, so that it does not interfer
            // with the next test run in the loop
            unset($GLOBALS['_OA']['DB_TABLES']['tmp_ad_required_impression']);
            $oTable = OA_DB_Table_Priority::singleton();
            foreach ($oTable->aDefinition['tables'] as $tableName => $aTable) {
                $oTable->truncateTable($tableName);
                $oTable->dropTable($tableName);
            }
        }
    }
}
