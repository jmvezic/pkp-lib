<?php

/**
 * @file controllers/grid/users/reviewer/form/ReviewReminderForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ReviewReminderForm
 * @ingroup controllers_grid_users_reviewer_form
 *
 * @brief Form for sending a review reminder to a reviewer
 */

use APP\notification\NotificationManager;
use APP\template\TemplateManager;
use PKP\form\Form;

use PKP\mail\SubmissionMailTemplate;
use PKP\notification\PKPNotification;

class ReviewReminderForm extends Form
{
    /** The review assignment associated with the reviewer **/
    public $_reviewAssignment;

    /**
     * Constructor.
     */
    public function __construct($reviewAssignment)
    {
        parent::__construct('controllers/grid/users/reviewer/form/reviewReminderForm.tpl');
        $this->_reviewAssignment = $reviewAssignment;
        AppLocale::requireComponents(LOCALE_COMPONENT_APP_SUBMISSION);

        // Validation checks for this form
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }

    //
    // Getters and Setters
    //
    /**
     * Get the review assignment
     *
     * @return ReviewAssignment
     */
    public function getReviewAssignment()
    {
        return $this->_reviewAssignment;
    }


    //
    // Overridden template methods
    //
    /**
     * @copydoc Form::initData
     */
    public function initData()
    {
        $request = Application::get()->getRequest();
        $userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */
        $user = $request->getUser();
        $context = $request->getContext();

        $reviewAssignment = $this->getReviewAssignment();
        $reviewerId = $reviewAssignment->getReviewerId();
        $reviewer = $userDao->getById($reviewerId);

        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
        $submission = $submissionDao->getById($reviewAssignment->getSubmissionId());

        $context = $request->getContext();
        $templateKey = $this->_getMailTemplateKey($context);

        $email = new SubmissionMailTemplate($submission, $templateKey);

        // Format the review due date
        $reviewDueDate = strtotime($reviewAssignment->getDateDue());
        $dateFormatShort = $context->getLocalizedDateFormatShort();
        if ($reviewDueDate == -1) {
            $reviewDueDate = $dateFormatShort;
        } // Default to something human-readable if no date specified
        else {
            $reviewDueDate = strftime($dateFormatShort, $reviewDueDate);
        }

        $dispatcher = $request->getDispatcher();
        $paramArray = [
            'reviewerName' => $reviewer->getFullName(),
            'reviewDueDate' => $reviewDueDate,
            'editorialContactSignature' => $user->getContactSignature(),
            'reviewerUserName' => $reviewer->getUsername(),
            'passwordResetUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'login', 'resetPassword', $reviewer->getUsername(), ['confirm' => Validation::generatePasswordResetHash($reviewer->getId())]),
            'submissionReviewUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'reviewer', 'submission', null, ['submissionId' => $reviewAssignment->getSubmissionId()])
        ];
        $email->assignParams($paramArray);

        $this->setData('stageId', $reviewAssignment->getStageId());
        $this->setData('reviewAssignmentId', $reviewAssignment->getId());
        $this->setData('submissionId', $submission->getId());
        $this->setData('reviewAssignment', $reviewAssignment);
        $this->setData('reviewerName', $reviewer->getFullName() . ' <' . $reviewer->getEmail() . '>');
        $this->setData('message', $email->getBody());
        $this->setData('reviewDueDate', $reviewDueDate);
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $context = $request->getContext();
        $user = $request->getUser();

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('emailVariables', [
            'reviewerName' => __('user.name'),
            'reviewDueDate' => __('reviewer.submission.reviewDueDate'),
            'submissionReviewUrl' => __('common.url'),
            'submissionTitle' => __('submission.title'),
            'passwordResetUrl' => __('common.url'),
            'contextName' => $context->getLocalizedName(),
            'editorialContactSignature' => $user->getContactSignature(),
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * Assign form data to user-submitted data.
     *
     * @see Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars([
            'message',
            'reviewDueDate',
        ]);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO'); /** @var SubmissionDAO $submissionDao */
        $request = Application::get()->getRequest();

        $reviewAssignment = $this->getReviewAssignment();
        $reviewerId = $reviewAssignment->getReviewerId();
        $reviewer = $userDao->getById($reviewerId);
        $submission = $submissionDao->getById($reviewAssignment->getSubmissionId());
        $reviewDueDate = $this->getData('reviewDueDate');
        $dispatcher = $request->getDispatcher();
        $user = $request->getUser();

        $context = $request->getContext();
        $templateKey = $this->_getMailTemplateKey($context);
        $email = new SubmissionMailTemplate($submission, $templateKey, null, null, null, false);

        $reviewUrlArgs = ['submissionId' => $reviewAssignment->getSubmissionId()];
        if ($context->getData('reviewerAccessKeysEnabled')) {
            import('lib.pkp.classes.security.AccessKeyManager');
            $accessKeyManager = new AccessKeyManager();
            $expiryDays = ($context->getData('numWeeksPerReview') + 4) * 7;
            $accessKey = $accessKeyManager->createKey($context->getId(), $reviewerId, $reviewAssignment->getId(), $expiryDays);
            $reviewUrlArgs = array_merge($reviewUrlArgs, ['reviewId' => $reviewAssignment->getId(), 'key' => $accessKey]);
        }

        $email->addRecipient($reviewer->getEmail(), $reviewer->getFullName());
        $email->setBody($this->getData('message'));
        $email->assignParams([
            'reviewerName' => $reviewer->getFullName(),
            'reviewDueDate' => $reviewDueDate,
            'passwordResetUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'login', 'resetPassword', $reviewer->getUsername(), ['confirm' => Validation::generatePasswordResetHash($reviewer->getId())]),
            'submissionReviewUrl' => $dispatcher->url($request, PKPApplication::ROUTE_PAGE, null, 'reviewer', 'submission', null, $reviewUrlArgs),
            'editorialContactSignature' => $user->getContactSignature(),
        ]);
        if (!$email->send($request)) {
            $notificationMgr = new NotificationManager();
            $notificationMgr->createTrivialNotification($request->getUser()->getId(), PKPNotification::NOTIFICATION_TYPE_ERROR, ['contents' => __('email.compose.error')]);
        }

        // update the ReviewAssignment with the reminded and modified dates
        $reviewAssignment->setDateReminded(Core::getCurrentDate());
        $reviewAssignment->stampModified();
        $reviewAssignmentDao = DAORegistry::getDAO('ReviewAssignmentDAO'); /** @var ReviewAssignmentDAO $reviewAssignmentDao */
        $reviewAssignmentDao->updateObject($reviewAssignment);

        parent::execute(...$functionArgs);
    }

    /**
     * Get the email template key depending on if reviewer one click access is
     * enabled or not.
     *
     * @param $context Context The user's current context.
     *
     * @return int Email template key
     */
    public function _getMailTemplateKey($context)
    {
        $templateKey = 'REVIEW_REMIND';
        if ($context->getData('reviewerAccessKeysEnabled')) {
            $templateKey = 'REVIEW_REMIND_ONECLICK';
        }

        return $templateKey;
    }
}
