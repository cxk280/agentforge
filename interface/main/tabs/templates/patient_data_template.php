<?php

/**
 * Patient data template.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Robert Down <robertdown@live.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017-2023 Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2018 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

use OpenEMR\Core\OEGlobalsBag;

?>
<?php

$search_any_type = OEGlobalsBag::getInstance()->get('search_any_patient');

//Modes for search box :comprehensive, dual, fixed and the default is none
switch ($search_any_type) {
    case 'dual':
        $any_search_class = "any-search-legacy";
        $search_globals_class = "btn-globals-legacy";
        break;
    case 'comprehensive':
        $any_search_class = "any-search-modern";
        $search_globals_class = "btn-globals-modern";
        break;
    case 'fixed':
        $any_search_class = "any-search-fixed";
        $search_globals_class = "btn-globals-fixed";
        break;
    default:
        $any_search_class = "any-search-none";
        $search_globals_class = "btn-globals-none";
}

?>
<script type="text/html" id="patient-data-template">
    <!-- ko if: patient -->
    <div class="d-lg-inline-flex w-100 cp-demographics-banner">
        <div class="flex-fill">
            <div class="float-left mx-2">
                <!-- ko if: patient -->
                <div data-bind="with: patient" class="patientPicture cp-patient-photo">
                    <img data-bind="attr: {src: patient_picture}"
                        class="img-thumbnail"
                        width="48"
                        height="48"
                        onError="this.src = '<?php echo OEGlobalsBag::getInstance()->getKernel()->getImagesRelative(); ?>/patient-picture-default.png'" />
                </div>
                <!-- /ko -->
            </div>
            <div class="form-group">
                <!-- ko if: patient -->
                <?php
                $classes = "";
                $closeAnchorClasses = '';
                switch (OEGlobalsBag::getInstance()->get('patient_name_display')) :
                    case 'btn':
                        $classes = "btn btn-sm btn-secondary";
                        $wrapperElement = 'div';
                        $wrapperElementClass = 'btn-group btn-group-sm mb-2';
                        $closeElement = '';
                        $closeElementClass = '';
                        $closeIconClass = 'text-muted';
                        $pubpidElement = 'span';
                        break;
                    case 'text-large':
                        $closeAnchorClasses = 'text-muted';
                        $wrapperElement = 'h3';
                        $wrapperElementClass = 'd-inline';
                        $closeElement = 'small';
                        $closeElementClass = '';
                        $closeIconClass = 'text-muted fa-xs';
                        $pubpidElement = 'small';
                        break;
                    default:
                        $closeAnchorClasses = 'text-muted';
                        $wrapperElement = 'div';
                        $wrapperElementClass = 'd-inline';
                        $pubpidElement = 'span';
                        $closeElement = 'span';
                        $closeElementClass = '';
                        $closeIconClass = 'text-muted';
                        break;
                endswitch;
                echo "<$wrapperElement class=\"$wrapperElementClass\">";
                ?>
                    <a class="ptName <?php echo $classes ?? ''; ?> " data-bind="click:refreshPatient,with: patient" href="#" title="<?php echo xla("To Dashboard") ?>">
                        <span class="cp-pt-name" data-bind="text: pname()"></span>
                        <<?php echo $pubpidElement;?> class="text-muted cp-pt-mrn">#<span data-bind="text: pubpid"></span></<?php echo $pubpidElement;?>>
                    </a>
                <?php echo "</$wrapperElement>"; ?>

                <span class="cp-pt-dob">
                    <span data-bind="text:patient().str_dob()"></span>
                </span>
                <!-- /ko -->
            </div>
        </div>

        <div class="flex-column mx-2">
            <!-- ko if: user -->
            <!-- ko with: user -->
            <!-- ko if:messages() -->
            <span class="mr-auto">
                <a class="btn btn-secondary btn-sm" href="#" data-bind="click: viewMessages"
                    title="<?php echo xla("View Messages"); ?>">
                    <i class="fa fa-envelope"></i>&nbsp;<span class="badge badge-primary" style="display:inline" data-bind="text: messages()"></span>
                </a>
            </span>
            <!-- /ko --><!-- messages -->
            <!-- ko if: portal() -->
            <nav class="btn-group dropdown mr-auto">
                <button class="btn btn-secondary btn-sm dropdown-toggle"
                    type="button" id="portalMsgAlerts"
                    data-toggle="dropdown"
                    aria-haspopup="true"
                    aria-expanded="true">
                    <?php echo xlt("Portal"); ?>&nbsp;
                    <span class="badge badge-danger" data-bind="text: portalAlerts()"></span>
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right" aria-labelledby="portalMsgAlerts">
                    <li>
                        <a class="dropdown-item" href="#" data-bind="click: viewPortalMail">
                            <i class="fa fa-envelope"></i>&nbsp;<?php echo xlt("Portal Mail"); ?>&nbsp;
                            <span class="badge badge-success" style="display:inline" data-bind="text: portalMail()"></span>
                        </a>
                    </li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" data-bind="click: viewPortalAudits">
                            <i class="fa fa-align-justify"></i>&nbsp;<?php echo xlt("Portal Audits"); ?>&nbsp;
                            <span class="badge badge-success" style="display:inline" data-bind="text: portalAudits()"></span>
                        </a>
                    </li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" data-bind="click: viewPortalPayments">
                            <i class="fa fa-credit-card"></i>&nbsp;<?php echo xlt("Portal Payments"); ?>&nbsp;<span class="badge badge-success" style="display:inline" data-bind="text: portalPayments()"></span>
                        </a>
                    </li>
                </ul>
            </nav>
            <!-- /ko --><!-- portal alert -->
            <!-- ko if: servicesOther() -->
            <nav class="btn-group dropdown mr-auto">
                <button class="btn btn-secondary btn-sm dropdown-toggle"
                    type="button" id="servicesMsgAlerts"
                    data-toggle="dropdown"
                    aria-haspopup="true"
                    aria-expanded="true">
                    <?php echo xlt("Services"); ?>&nbsp;
                    <span class="badge badge-danger" data-bind="text: serviceAlerts()"></span>
                    <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right" aria-labelledby="servicesMsgAlerts">
                    <li>
                        <a class="dropdown-item" href="#" data-bind="click: viewFaxCount">
                            <i class="fa fa-solid fa-fax"></i>&nbsp;<?php echo xlt("Pending Faxes"); ?>&nbsp;
                            <span class="badge badge-success" style="display:inline" data-bind="text: faxAlerts()"></span>
                        </a>
                    </li>
                    <li class="dropdown-divider"></li>
                    <li>
                        <a class="dropdown-item" href="#" data-bind="click: viewSmsCount">
                            <i class="fa fa-sms"></i>&nbsp;<?php echo xlt("Pending SMS"); ?>&nbsp;
                            <span class="badge badge-success" style="display:inline" data-bind="text: smsAlerts()"></span>
                        </a>
                    </li>
                </ul>
            </nav>
            <!-- /ko --><!-- servicesOther alert -->
            <!-- /ko --><!-- with user -->
            <!-- /ko --><!-- user -->
        </div>
    </div>
    <!-- /ko --><!-- patient (outer banner gate) -->
</script>
