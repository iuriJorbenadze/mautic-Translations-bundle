<?php

namespace MauticPlugin\LeuchtfeuerTranslationsBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomButtonEvent;
use MauticPlugin\LeuchtfeuerTranslationsBundle\Service\FeatureGateService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class ButtonSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private LoggerInterface $logger,
        private TranslatorInterface $translator,
        private FeatureGateService $featureGate,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_BUTTONS => ['inject', 0],
        ];
    }

    public function inject(CustomButtonEvent $event): void
    {
        $loc = $event->getLocation();

        // Only the Options dropdown on the email detail page
        if ('page_actions' !== $loc) {
            return;
        }

        // Respect plugin toggle (Published switch in Plugins UI)
        if (!$this->featureGate->isEnabled()) {
            // $this->logger->info('[LeuchtfeuerTranslations] skipped button: integration disabled');
            return;
        }

        $this->logger->info('[LeuchtfeuerTranslations] injecting dropdown item', ['location' => $loc]);

        $dropdownItem = [
            'attr'      => [
                'id'         => 'ai-translate-dropdown',
                // Dropdown entries are links; no "btn ..." classes
                'class'      => ' -tertiary -nospin',
                'href'       => '#',     // inert; prevents accidental GETs
                'role'       => 'button',
                'aria-label' => $this->translator->trans('plugin.leuchtfeuertranslations.aria_label_ai_translate'),
                // No inline JS - just a marker
                'data-lf-translate' => '1',
            ],
            'btnText'   => $this->translator->trans('plugin.leuchtfeuertranslations.clone_translate_button'),
            'iconClass' => 'ri-global-line',
            'primary'   => false, // force dropdown only
            'priority'  => 0.5,   // ordering within dropdown
        ];

        // Only on /s/emails/view/{id}
        $routeFilter = ['mautic_email_action', ['objectAction' => 'view']];

        // Pass the explicit location name
        $event->addButton($dropdownItem, 'page_actions', $routeFilter);

        $this->logger->info('[LeuchtfeuerTranslations] dropdown item added', ['location' => $loc]);
    }
}
