<?php

final class PhabricatorAuthEditController
  extends PhabricatorAuthProviderConfigController {

  private $providerClass;
  private $configID;

  public function willProcessRequest(array $data) {
    $this->providerClass = idx($data, 'className');
    $this->configID = idx($data, 'id');
  }

  public function processRequest() {
    $request = $this->getRequest();
    $viewer = $request->getUser();

    if ($this->configID) {
      $config = id(new PhabricatorAuthProviderConfigQuery())
        ->setViewer($viewer)
        ->requireCapabilities(
          array(
            PhabricatorPolicyCapability::CAN_VIEW,
            PhabricatorPolicyCapability::CAN_EDIT,
          ))
        ->withIDs(array($this->configID))
        ->executeOne();
      if (!$config) {
        return new Aphront404Response();
      }

      $provider = $config->getProvider();
      if (!$provider) {
        return new Aphront404Response();
      }

      $is_new = false;
    } else {
      $providers = PhabricatorAuthProvider::getAllBaseProviders();
      foreach ($providers as $candidate_provider) {
        if (get_class($candidate_provider) === $this->providerClass) {
          $provider = $candidate_provider;
          break;
        }
      }

      if (!$provider) {
        return new Aphront404Response();
      }

      // TODO: When we have multi-auth providers, support them here.

      $configs = id(new PhabricatorAuthProviderConfigQuery())
        ->setViewer($viewer)
        ->withProviderClasses(array(get_class($provider)))
        ->execute();

      if ($configs) {
        // TODO: We could link to the other config's edit interface here.
        throw new Exception("This provider is already configured!");
      }

      $config = id(new PhabricatorAuthProviderConfig())
        ->setProviderClass(get_class($provider))
        ->setShouldAllowLogin(1)
        ->setShouldAllowRegistration(1)
        ->setShouldAllowLink(1)
        ->setShouldAllowUnlink(1);

      $is_new = true;
    }

    $errors = array();

    $v_registration = $config->getShouldAllowRegistration();
    $v_link = $config->getShouldAllowLink();
    $v_unlink = $config->getShouldAllowUnlink();

    if ($request->isFormPost()) {

      $properties = $provider->readFormValuesFromRequest($request);
      list($errors, $issues, $properties) = $provider->processEditForm(
        $request,
        $properties);

      $xactions = array();

      if (!$errors) {
        if ($is_new) {
          $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
            ->setTransactionType(
              PhabricatorAuthProviderConfigTransaction::TYPE_ENABLE)
            ->setNewValue(1);

          $config->setProviderType($provider->getProviderType());
          $config->setProviderDomain($provider->getProviderDomain());
        }

        $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
          ->setTransactionType(
            PhabricatorAuthProviderConfigTransaction::TYPE_REGISTRATION)
          ->setNewValue($request->getInt('allowRegistration', 0));

        $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
          ->setTransactionType(
            PhabricatorAuthProviderConfigTransaction::TYPE_LINK)
          ->setNewValue($request->getInt('allowLink', 0));

        $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
          ->setTransactionType(
            PhabricatorAuthProviderConfigTransaction::TYPE_UNLINK)
          ->setNewValue($request->getInt('allowUnlink', 0));

        foreach ($properties as $key => $value) {
          $xactions[] = id(new PhabricatorAuthProviderConfigTransaction())
            ->setTransactionType(
              PhabricatorAuthProviderConfigTransaction::TYPE_PROPERTY)
            ->setMetadataValue('auth:property', $key)
            ->setNewValue($value);
        }

        $editor = id(new PhabricatorAuthProviderConfigEditor())
          ->setActor($viewer)
          ->setContentSourceFromRequest($request)
          ->setContinueOnNoEffect(true)
          ->applyTransactions($config, $xactions);

        return id(new AphrontRedirectResponse())->setURI(
          $this->getApplicationURI());
      }
    } else {
      $properties = $provider->readFormValuesFromProvider();
      $issues = array();
    }

    if ($errors) {
      $errors = id(new AphrontErrorView())->setErrors($errors);
    }

    if ($is_new) {
      $button = pht('Add Provider');
      $crumb = pht('Add Provider');
      $title = pht('Add Authentication Provider');
      $cancel_uri = $this->getApplicationURI('/config/new/');
    } else {
      $button = pht('Save');
      $crumb = pht('Edit Provider');
      $title = pht('Edit Authentication Provider');
      $cancel_uri = $this->getApplicationURI();
    }

    $str_registration = hsprintf(
      '<strong>%s:</strong> %s',
      pht('Allow Registration'),
      pht(
        'Allow users to register new Phabricator accounts using this '.
        'provider. If you disable registration, users can still use this '.
        'provider to log in to existing accounts, but will not be able to '.
        'create new accounts.'));

    $str_link = hsprintf(
      '<strong>%s:</strong> %s',
      pht('Allow Linking Accounts'),
      pht(
        'Allow users to link account credentials for this provider to '.
        'existing Phabricator accounts. There is normally no reason to '.
        'disable this unless you are trying to move away from a provider '.
        'and want to stop users from creating new account links.'));

    $str_unlink = hsprintf(
      '<strong>%s:</strong> %s',
      pht('Allow Unlinking Accounts'),
      pht(
        'Allow users to unlink account credentials for this provider from '.
        'existing Phabricator accounts. If you disable this, Phabricator '.
        'accounts will be permanently bound to provider accounts.'));

    $status_tag = id(new PhabricatorTagView())
      ->setType(PhabricatorTagView::TYPE_STATE);
    if ($config->getIsEnabled()) {
      $status_tag
        ->setName(pht('Enabled'))
        ->setBackgroundColor('green');
    } else {
      $status_tag
        ->setName(pht('Disabled'))
        ->setBackgroundColor('red');
    }

    $form = id(new AphrontFormView())
      ->setUser($viewer)
      ->setFlexible(true)
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel(pht('Provider'))
          ->setValue($provider->getProviderName()))
      ->appendChild(
        id(new AphrontFormStaticControl())
          ->setLabel(pht('Status'))
          ->setValue($status_tag))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->setLabel(pht('Allow'))
          ->addCheckbox(
            'allowRegistration',
            1,
            $str_registration,
            $v_registration))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'allowLink',
            1,
            $str_link,
            $v_link))
      ->appendChild(
        id(new AphrontFormCheckboxControl())
          ->addCheckbox(
            'allowUnlink',
            1,
            $str_unlink,
            $v_unlink));

    $provider->extendEditForm($request, $form, $properties, $issues);

    $form
      ->appendChild(
        id(new AphrontFormSubmitControl())
          ->addCancelButton($cancel_uri)
          ->setValue($button));

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($crumb));

    $xaction_view = null;
    if (!$is_new) {
      $xactions = id(new PhabricatorAuthProviderConfigTransactionQuery())
        ->withObjectPHIDs(array($config->getPHID()))
        ->setViewer($viewer)
        ->execute();

      foreach ($xactions as $xaction) {
        $xaction->setProvider($provider);
      }

      $xaction_view = id(new PhabricatorApplicationTransactionView())
        ->setUser($viewer)
        ->setTransactions($xactions);
    }

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $errors,
        $form,
        $xaction_view,
      ),
      array(
        'title' => $title,
        'dust' => true,
        'device' => true,
      ));
  }

}