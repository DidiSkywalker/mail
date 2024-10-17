<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Listener;

use Horde_Imap_Client;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Db\Tag;
use OCA\Mail\Db\TagMapper;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\AiIntegrations\AiIntegrationsService;
use OCA\Mail\Service\Classification\ClassificationSettingsService;
use OCA\Mail\Service\Classification\ImportanceClassifier;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use OCP\IConfig;


/**
 * @template-implements IEventListener<Event|NewMessagesSynchronized>
 */
class NewMessageClassificationListener implements IEventListener {
	private const EXEMPT_FROM_CLASSIFICATION = [
		Horde_Imap_Client::SPECIALUSE_ARCHIVE,
		Horde_Imap_Client::SPECIALUSE_DRAFTS,
		Horde_Imap_Client::SPECIALUSE_JUNK,
		Horde_Imap_Client::SPECIALUSE_SENT,
		Horde_Imap_Client::SPECIALUSE_TRASH,
	];

	/** @var ImportanceClassifier */
	private $classifier;

	/** @var TagMapper */
	private $tagMapper;

	/** @var LoggerInterface */
	private $logger;

	/** @var IMailManager */
	private $mailManager;

	private ClassificationSettingsService $classificationSettingsService;
	private AiIntegrationsService $aiIntegrationsService;

	public function __construct(ImportanceClassifier $classifier,
		TagMapper $tagMapper,
		LoggerInterface $logger,
		IMailManager $mailManager,
		ClassificationSettingsService $classificationSettingsService,
		AiIntegrationsService $aiIntegrationsService
		) {
		$this->classifier = $classifier;
		$this->logger = $logger;
		$this->tagMapper = $tagMapper;
		$this->mailManager = $mailManager;
		$this->classificationSettingsService = $classificationSettingsService;
		$this->aiIntegrationsService = $aiIntegrationsService;
	}

	public function handle(Event $event): void {
    if (!($event instanceof NewMessagesSynchronized)) {
        return;
    }

    if (!$this->classificationSettingsService->isClassificationEnabled($event->getAccount()->getUserId())) {
        return;
    }

    foreach (self::EXEMPT_FROM_CLASSIFICATION as $specialUse) {
        if ($event->getMailbox()->isSpecialUse($specialUse)) {
            // Nothing to do then
            return;
        }
    }

    $messages = $event->getMessages();

    // if this is a message that's been flagged / tagged as important before, we don't want to reclassify it again.
    $doNotReclassify = $this->tagMapper->getTaggedMessageIdsForMessages(
        $event->getMessages(),
        $event->getAccount()->getUserId(),
        Tag::LABEL_IMPORTANT
    );
    $messages = array_filter($messages, static function ($message) use ($doNotReclassify) {
        return ($message->getFlagImportant() === false || in_array($message->getMessageId(), $doNotReclassify, true));
    });

    $optionalTags = [
        Tag::LABEL_IMPORTANT,
        Tag::LABEL_LATER,
        Tag::LABEL_PERSONAL,
        Tag::LABEL_TODO,
        Tag::LABEL_WORK,
    ];

    $tags = [];

    foreach ($optionalTags as $label) {
        try {
            $tag = $this->tagMapper->getTagByImapLabel($label, $event->getAccount()->getUserId());
            $tags[$label] = $tag;
        } catch (DoesNotExistException $e) {
            // Log the error and continue with the next label
            $this->logger->error('Could not find tag for label ' . $label . ' for user ' . $event->getAccount()->getUserId() . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    try {
        // Get smart tags from AI integration service
        foreach ($messages as $message) {
            $smartTags = $this->aiIntegrationsService->getSmartTags(
                $event->getAccount(),
                $event->getMailbox(),
                $message,
                $event->getAccount()->getUserId()
            );

            foreach ($smartTags as $smartTag) {
                if (isset($tags[$smartTag])) {
                    // Apply the tag to the message
                    $this->mailManager->tagMessage($event->getAccount(), $event->getMailbox()->getName(), $message, $tags[$smartTag], true);
                    if ($smartTag === Tag::LABEL_IMPORTANT) {
                        // Additionally flag the message as important
                        $this->mailManager->flagMessage($event->getAccount(), $event->getMailbox()->getName(), $message->getUid(), Tag::LABEL_IMPORTANT, true);
                    }
                }
            }
        }
    } catch (ServiceException $e) {
        $this->logger->error('Could not classify incoming message importance: ' . $e->getMessage(), [
            'exception' => $e,
        ]);
    }
}
}