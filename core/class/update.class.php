<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/

/* * ***************************Includes********************************* */
require_once __DIR__ . '/../../core/php/core.inc.php';

class update {
	/*     * *************************Attributs****************************** */

	private $id;
	private $type = 'plugin';
	private $logicalId;
	private $name;
	private $localVersion;
	private $remoteVersion;
	private $status;
	private $configuration;
	private $source = 'market';
	private $updateDate;
	private $_changeUpdate = false;
	private $_changed = false;

	/*     * ***********************Méthodes statiques*************************** */

	public static function checkAllUpdate($_filter = '', $_findNewObject = true) {
		$findCore = false;
		if ($_findNewObject) {
			self::findNewUpdateObject();
		}
		$updates = self::all($_filter);
		$updates_sources = array();
		if (is_array($updates)) {
			foreach (self::all($_filter) as $update) {
				if ($update->getType() == 'core') {
					if ($findCore) {
						$update->remove();
						continue;
					}
					$findCore = true;
					$update->setType('core');
					$update->setLogicalId('jeedom');
					$update->setSource(config::byKey('core::repo::provider'));
					$update->setLocalVersion(jeedom::version());
					$update->save();
					$update->checkUpdate();
				} else {
					if ($update->getStatus() != 'hold') {
						if (!isset($updates_sources[$update->getSource()])) {
							$updates_sources[$update->getSource()] = array();
						}
						$updates_sources[$update->getSource()][] = $update;
					}
				}
			}
		}
		if (!$findCore && ($_filter == '' || $_filter == 'core')) {
			$update = new update();
			$update->setType('core');
			$update->setLogicalId('jeedom');
			$update->setSource(config::byKey('core::repo::provider'));
			$update->setLocalVersion(jeedom::version());
			$update->save();
			$update->checkUpdate();
		}
		foreach ($updates_sources as $source => $updates) {
			$class = 'repo_' . $source;
			if (class_exists($class) && method_exists($class, 'checkUpdate') && config::byKey($source . '::enable') == 1) {
				$class::checkUpdate($updates);
			}
		}
		config::save('update::lastCheck', date('Y-m-d H:i:s'));
	}

	public static function listRepo() {
		$return = array();
		foreach (ls(__DIR__ . '/../repo', '*.repo.php') as $file) {
			if (substr_count($file, '.') != 2) {
				continue;
			}
			$class = 'repo_' . str_replace('.repo.php', '', $file);
			$return[str_replace('.repo.php', '', $file)] = array(
				'name' => $class::$_name,
				'class' => $class,
				'configuration' => (method_exists($class, 'getConfigurationOption')) ? $class::getConfigurationOption() : array(),
				'scope' => $class::$_scope,
			);
			$return[str_replace('.repo.php', '', $file)]['enable'] = config::byKey(str_replace('.repo.php', '', $file) . '::enable');
		}
		return $return;
	}

	public static function repoById($_id) {
		$class = 'repo_' . $_id;
		$return = array(
			'name' => $class::$_name,
			'configuration' => (method_exists($class, 'getConfigurationOption')) ? $class::getConfigurationOption() : array(),
			'scope' => $class::$_scope,
		);
		$return['enable'] = config::byKey($_id . '::enable');
		return $return;
	}

	public static function updateAll($_filter = '') {
		if ($_filter == 'core') {
			foreach (self::byType($_filter) as $update) {
				$update->doUpdate();
			}
		} else {
			$error = false;
			if ($_filter == '') {
				$updates = self::all();
			} else {
				$updates = self::byType($_filter);
			}
			if (is_array($updates)) {
				foreach ($updates as $update) {
					if ($update->getStatus() != 'hold' && $update->getStatus() == 'update' && $update->getType() != 'core') {
						try {
							$update->doUpdate();
						} catch (Exception $e) {
							log::add(__CLASS__, 'alert', $e->getMessage());
							$error = true;
						} catch (Error $e) {
							log::add(__CLASS__, 'alert', $e->getMessage());
							$error = true;
						}
					}
				}
			}
			return $error;
		}
	}

	public static function byId($_id) {
		$values = array(
			'id' => $_id,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM `update`
		WHERE id=:id';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byStatus($_status) {
		$values = array(
			'status' => $_status,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM `update`
		WHERE status=:status';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byLogicalId($_logicalId) {
		$values = array(
			'logicalId' => $_logicalId,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM `update`
		WHERE logicalId=:logicalId';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byType($_type) {
		$values = array(
			'type' => $_type,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM `update`
		WHERE type=:type';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byTypeAndLogicalId($_type, $_logicalId) {
		$values = array(
			'logicalId' => $_logicalId,
			'type' => $_type,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM `update`
		WHERE logicalId=:logicalId
		AND type=:type';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	/**
	 *
	 * @return array de tous les utilisateurs
	 */
	public static function all($_filter = '') {
		$values = array();
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM `update`';
		if ($_filter != '') {
			$values['type'] = $_filter;
			$sql .= ' WHERE `type`=:type';
		}
		$sql .= ' ORDER BY FIELD( `status`, "update","ok","depreciated") ASC,FIELD( `type`,"plugin","core") DESC, `name` ASC';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function nbNeedUpdate() {
		$value = array(
			'configuration' => '%"doNotUpdate":"1"%'
		);
		$sql = 'SELECT count(*)
		FROM `update`
		WHERE `status`="update"
		AND `configuration` NOT LIKE :configuration';
		$result = DB::Prepare($sql, $value, DB::FETCH_TYPE_ROW);
		return $result['count(*)'];
	}

	public static function findNewUpdateObject() {
		foreach ((plugin::listPlugin()) as $plugin) {
			$plugin_id = $plugin->getId();
			$update = self::byTypeAndLogicalId('plugin', $plugin_id);
			if (!is_object($update)) {
				$update = new update();
				$update->setLogicalId($plugin_id);
				$update->setType('plugin');
				$update->setLocalVersion(date('Y-m-d H:i:s'));
				$update->save();
			}
			$find = array();
			if (method_exists($plugin_id, 'listMarketObject')) {
				foreach (($plugin_id::listMarketObject()) as $logical_id) {
					$find[$logical_id] = true;
					$update = self::byTypeAndLogicalId($plugin_id, $logical_id);
					if (!is_object($update)) {
						$update = new update();
						$update->setLogicalId($logical_id);
						$update->setType($plugin_id);
						$update->setLocalVersion(date('Y-m-d H:i:s'));
						$update->save();
					}
				}
				foreach (self::byType($plugin_id) as $update) {
					if (!isset($find[$update->getLogicalId()])) {
						$update->remove();
					}
				}
			} else {
				$values = array(
					'type' => $plugin_id,
				);
				$sql = 'DELETE FROM `update`
				WHERE type=:type';
				DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW);
			}
		}
	}

	public static function listCoreUpdate() {
		return ls(__DIR__ . '/../../install/update', '*');
	}

	/*     * *********************Méthodes d'instance************************* */

	public function getInfo() {
		if ($this->getType() != 'core') {
			$class = 'repo_' . $this->getSource();
			if (class_exists($class) && method_exists($class, 'objectInfo') && config::byKey($this->getSource() . '::enable') == 1) {
				return $class::objectInfo($this);
			}
		}
		return array();
	}

	public function doUpdate() {
		if ($this->getConfiguration('doNotUpdate') == 1  && $this->getType() != 'core') {
			log::add(__CLASS__, 'alert', __('Vérification des mises à jour, mise à jour et réinstallation désactivées sur', __FILE__) . ' ' . $this->getLogicalId());
			return;
		}
		if ($this->getType() == 'core') {
			jeedom::update();
		} else {
			log::add(__CLASS__, 'alert', __('Début de la mise à jour de :', __FILE__) . ' ' . $this->getLogicalId() . "\n");
			$class = 'repo_' . $this->getSource();
			if (class_exists($class) && method_exists($class, 'downloadObject') && config::byKey($this->getSource() . '::enable') == 1) {
				$cibDir = jeedom::getTmpFolder('market') . '/' . $this->getLogicalId();
				if (file_exists($cibDir)) {
					rrmdir($cibDir);
				}
				mkdir($cibDir);
				if (!file_exists($cibDir) && !mkdir($cibDir, 0775, true)) {
					throw new Exception(__('Impossible de créer le dossier', __FILE__) . ' : ' . $cibDir . '. ' . __('Problème de droits ?', __FILE__));
				}
				log::add(__CLASS__, 'alert', __('Téléchargement du plugin (source', __FILE__) . ' : ' . $this->getSource() . ')...');
				$info = $class::downloadObject($this);
				if ($info['path'] !== false) {
					$tmp = $info['path'];
					log::add(__CLASS__, 'alert', __("OK\n", __FILE__));

					if (filesize($tmp) < 100) {
						throw new Exception(__("Echec lors du téléchargement du plugin (taille inférieure à 100 octets), veuillez réessayer plus tard. Cela peut être dû à une absence de connexion au market (effectuez un test de connexion depuis la configuration générale), lié à un manque d'espace disque, une version minimale requise ou un souci sur le plugin ou son achat, etc...", __FILE__));
					}
					$extension = strtolower(strrchr($tmp, '.'));
					if (!in_array($extension, array('.zip'))) {
						throw new Exception(__('Extension du fichier non valide (autorisé .zip) :', __FILE__) . ' ' . $extension);
					}
					log::add(__CLASS__, 'alert', __('Décompression du zip...', __FILE__));
					$zip = new ZipArchive;
					$res = $zip->open($tmp);
					if ($res === TRUE) {
						if (!$zip->extractTo($cibDir . '/')) {
							$content = file_get_contents($tmp);
							throw new Exception(__("Impossible d'installer le plugin. Les fichiers n'ont pas pu être décompressés", __FILE__) . ' : ' . substr($content, 255));
						}
						$zip->close();
						unlink($tmp);
						if (!file_exists($cibDir . '/plugin_info')) {
							$files = ls($cibDir, '*');
							if (count($files) == 1 && file_exists($cibDir . '/' . $files[0] . 'plugin_info')) {
								$cibDir = $cibDir . '/' . $files[0];
							}
						}
						if (is_file($cibDir . '/plugin_info/info.json') && is_array($data = json_decode(file_get_contents($cibDir . '/plugin_info/info.json'), true)) && isset($data['require'])) {
							$vJeedom = jeedom::version();
							if (version_compare($data['require'], $vJeedom, '>')) {
								log::add(__CLASS__, 'alert', 'KO ' . __("Version minimale requise", __FILE__) . ' (' . $data['require'] . ') > ' . __("Version Jeedom", __FILE__) . ' (' . $vJeedom . ')');
								rrmdir($cibDir);
								$cibDir = jeedom::getTmpFolder('market') . '/' . $this->getLogicalId();
								if (file_exists($cibDir)) {
									rrmdir($cibDir);
								}
								throw new Exception($this->getLogicalId() . ' : ' . __('Version du core Jeedom non supportée, installation annulée', __FILE__));
							}
						}
						log::add(__CLASS__, 'alert', __("OK\n", __FILE__));
						$this->preInstallUpdate();
						try {
							if (file_exists(__DIR__ . '/../../plugins/' . $this->getLogicalId() . '/doc')) {
								shell_exec('sudo rm -rf ' . __DIR__ . '/../../plugins/' . $this->getLogicalId() . '/doc');
							}
							if (file_exists(__DIR__ . '/../../plugins/' . $this->getLogicalId() . '/docs')) {
								shell_exec('sudo rm -rf ' . __DIR__ . '/../../plugins/' . $this->getLogicalId() . '/docs');
							}
						} catch (Exception $e) {
						}
						rmove($cibDir . '/', __DIR__ . '/../../plugins/' . $this->getLogicalId(), false, array(), true);
						rrmdir($cibDir);
						$cibDir = jeedom::getTmpFolder('market') . '/' . $this->getLogicalId();
						if (file_exists($cibDir)) {
							rrmdir($cibDir);
						}
					} else {
						throw new Exception(__("Impossible de décompresser l'archive zip", __FILE__) . ' : ' . $tmp . ' => ' . ZipErrorMessage($res));
					}
				}
				$this->postInstallUpdate($info);
			}
		}
		$this->refresh();
		$this->checkUpdate();
	}

	public function deleteObjet() {
		if ($this->getType() == 'core') {
			throw new Exception(__('Vous ne pouvez pas supprimer le core de Jeedom', __FILE__));
		} else {
			switch ($this->getType()) {
				case 'plugin':
					try {
						$plugin = plugin::byId($this->getLogicalId());
						if (is_object($plugin)) {
							foreach (eqLogic::byType($this->getLogicalId()) as $eqLogic) {
								try {
									$eqLogic->remove();
								} catch (Exception $e) {
								} catch (Error $e) {
								}
							}
							try {
								$plugin->setIsEnable(0);
							} catch (Exception $e) {
							} catch (Error $e) {
							}
						}
						config::remove('*', $this->getLogicalId());
					} catch (Exception $e) {
					} catch (Error $e) {
					}
					break;
			}
			try {
				$class = 'repo_' . $this->getSource();
				if (class_exists($class) && method_exists($class, 'deleteObjet') && config::byKey($this->getSource() . '::enable') == 1) {
					$class::deleteObjet($this);
				}
			} catch (Exception $e) {
			}
			switch ($this->getType()) {
				case 'plugin':
					$cibDir = __DIR__ . '/../../plugins/' . $this->getLogicalId();
					if (file_exists($cibDir)) {
						rrmdir($cibDir);
					}
					break;
			}
			$this->remove();
		}
	}

	public function preInstallUpdate() {
		if (!file_exists(__DIR__ . '/../../plugins')) {
			mkdir(__DIR__ . '/../../plugins');
		}
		shell_exec(system::getCmdSudo() . ' chown -R ' . system::get('www-uid') . ':' . system::get('www-gid') . ' ' . __DIR__ . '/../../plugins');
		@chmod(__DIR__ . '/../../plugins', 0775);
		$cibDir = __DIR__ . '/../../plugins/' . $this->getLogicalId();
		switch ($this->getType()) {
			case 'plugin':
				if (!file_exists($cibDir) && !mkdir($cibDir, 0775, true)) {
					throw new Exception(__('Impossible de créer le dossier  :', __FILE__) . ' ' . $cibDir . __('Problème de droits ?', __FILE__));
				}
				try {
					$plugin = plugin::byId($this->getLogicalId());
					if (is_object($plugin)) {
						log::add(__CLASS__, 'alert', __('Action de pré-update...', __FILE__));
						$plugin->callInstallFunction('pre_update');
						log::add(__CLASS__, 'alert', __("OK\n", __FILE__));
					}
				} catch (Exception $e) {
				} catch (Error $e) {
				}
		}
	}

	public function postInstallUpdate($_infos) {
		log::add(__CLASS__, 'alert', __('Post-installation de', __FILE__) . ' ' . $this->getLogicalId() . '...');
		try {
			if (function_exists('opcache_reset')) {
				opcache_reset();
			}
		} catch (\Exception $e) {
		}
		switch ($this->getType()) {
			case 'plugin':
				try {
					$plugin = plugin::byId($this->getLogicalId());
					$cibDir = __DIR__ . '/../../plugins/' . $this->getLogicalId();
					log::add(__CLASS__, 'alert',  __('Vérification des droits sur les fichiers...', __FILE__));
					$cmd = system::getCmdSudo() . 'chown -R ' . system::get('www-uid') . ':' . system::get('www-gid') . ' ' . $cibDir . ';';
					$cmd .= system::getCmdSudo() . 'chmod 775 -R ' . $cibDir . ';';
					$cmd .= system::getCmdSudo() . 'chmod 775 -R ' . $cibDir . '/.*;';
					exec($cmd);
					log::add(__CLASS__, 'alert', __("OK", __FILE__) . "\n");
					log::add(__CLASS__, 'alert',  __('Suppression des fichiers inutiles...', __FILE__));
					foreach (array('3rdparty', '3rparty', 'desktop', 'mobile', 'core', 'docs', 'install', 'script', 'vendor', 'plugin_info') as $folder) {
						if (!file_exists($cibDir . '/' . $folder)) {
							continue;
						}
						shell_exec('find ' . $cibDir . '/' . $folder . '/* -mtime +7 -type f ! -iname "custom.*" ! -iname "common.config.php" ! -path "./vendor/*" -delete 2>/dev/null');
					}
				} catch (Exception $e) {
					$this->remove();
					throw new Exception(__("Impossible d'installer le plugin. Le nom du plugin est différent de l'ID ou le plugin n'est pas correctement formé. Veuillez contacter l'auteur", __FILE__));
				} catch (Error $e) {
					$this->remove();
					throw new Exception(__("Impossible d'installer le plugin. Le nom du plugin est différent de l'ID ou le plugin n'est pas correctement formé. Veuillez contacter l'auteur", __FILE__));
				}
				if (is_object($plugin) && $plugin->isActive()) {
					$plugin->setIsEnable(1);
				}
				break;
		}
		if (isset($_infos['localVersion'])) {
			$this->setLocalVersion($_infos['localVersion']);
		}
		$this->setUpdateDate(date('Y-m-d H:i:s'));
		$this->save();
		log::add(__CLASS__, 'alert', __("OK", __FILE__) . "\n");
		log::add(__CLASS__, 'alert', __("END UPDATE SUCCESS", __FILE__) . "\n");
	}

	public static function getLastAvailableVersion() {
		try {
			$url = 'https://raw.githubusercontent.com/jeedom/core/' . config::byKey('core::branch', 'core', 'V4-stable') . '/core/config/version';
			$request_http = new com_http($url);
			return trim($request_http->exec(30));
		} catch (Exception $e) {
			log::add(__CLASS__, 'error', __('Erreur lors de la récuperation de la derniere version de Jeedom, url :', __FILE__) . ' ' . $url . ' => ' . $e->getMessage());
		} catch (Error $e) {
			log::add(__CLASS__, 'error', __('Erreur lors de la récuperation de la derniere version de Jeedom, url :', __FILE__) . ' ' . $url . ' => ' . $e->getMessage());
		}
		return null;
	}

	public function checkUpdate() {
		if ($this->getConfiguration('doNotUpdate') == 1 && $this->getType() != 'core') {
			log::add(__CLASS__, 'alert', __('Vérification des mises à jour, mise à jour et réinstallation désactivées sur', __FILE__) . ' ' . $this->getLogicalId());
			return;
		}
		if ($this->getType() == 'core') {
			if (config::byKey('update::allowCore', 'core', 1) != 1) {
				return;
			}
			if (config::byKey('core::repo::provider') == 'default') {
				$this->setRemoteVersion(self::getLastAvailableVersion(true));
			} else {
				$class = 'repo_' . config::byKey('core::repo::provider');
				if (!method_exists($class, 'versionCore') || config::byKey(config::byKey('core::repo::provider') . '::enable') != 1) {
					$version = $this->getLocalVersion();
				} else {
					$version = $class::versionCore();
					if ($version === null) {
						$version = $this->getLocalVersion();
					}
				}
				$this->setRemoteVersion($version);
			}
			if (version_compare($this->getRemoteVersion(), $this->getLocalVersion(), '>')) {
				$this->setStatus('update');
			} else {
				$this->setStatus('ok');
			}
			$this->save();
		} else {
			try {
				$class = 'repo_' . $this->getSource();
				if (class_exists($class) && method_exists($class, 'checkUpdate') && config::byKey($this->getSource() . '::enable') == 1) {
					$class::checkUpdate($this);
				}
			} catch (Exception $ex) {
			} catch (Error $ex) {
			}
		}
	}

	public function preSave() {
		if ($this->getLogicalId() == '') {
			throw new Exception(__('Le logical ID ne peut pas être vide', __FILE__));
		}
		if ($this->getName() == '') {
			$this->setName($this->getLogicalId());
		}
		$repo = self::listRepo();
		if ($this->getSource() != 'default' && !isset($repo[$this->getSource()])) {
			throw new Exception(__('Source de mise à jour invalide : ', __FILE__) . $this->getSource());
		}
	}

	public function save() {
		return DB::save($this);
	}

	public function postSave() {
		if ($this->_changeUpdate) {
			event::add('update::refreshUpdateNumber');
		}
	}

	public function remove() {
		return DB::remove($this);
	}

	public function postRemove() {
		event::add('update::refreshUpdateNumber');
	}

	public function refresh() {
		DB::refresh($this);
	}

	/*     * **********************Getteur Setteur*************************** */

	public function getId() {
		return $this->id;
	}

	public function getName() {
		return $this->name;
	}

	public function getStatus() {
		return $this->status;
	}

	public function getConfiguration($_key = '', $_default = '') {
		return utils::getJsonAttr($this->configuration, $_key, $_default);
	}

	public function setId($_id) {
		$this->_changed = utils::attrChanged($this->_changed, $this->id, $_id);
		$this->id = $_id;
		return $this;
	}

	public function setName($_name) {
		$this->_changed = utils::attrChanged($this->_changed, $this->name, $_name);
		$this->name = $_name;
		return $this;
	}

	public function setStatus($_status) {
		if ($_status != $this->status) {
			$this->_changeUpdate = true;
			$this->_changed = true;
		}
		$this->status = $_status;
		return $this;
	}

	public function setConfiguration($_key, $_value) {
		$configuration = utils::setJsonAttr($this->configuration, $_key, $_value);
		$this->_changed = utils::attrChanged($this->_changed, $this->configuration, $configuration);
		$this->configuration = $configuration;
		return $this;
	}

	public function getType() {
		return $this->type;
	}

	public function setType($_type) {
		$this->_changed = utils::attrChanged($this->_changed, $this->type, $_type);
		$this->type = $_type;
		return $this;
	}

	public function getLocalVersion() {
		return $this->localVersion;
	}

	public function getRemoteVersion() {
		return $this->remoteVersion;
	}

	public function setLocalVersion($_localVersion) {
		$this->_changed = utils::attrChanged($this->_changed, $this->localVersion, $_localVersion);
		$this->localVersion = $_localVersion;
		return $this;
	}

	public function setRemoteVersion($_remoteVersion) {
		$this->_changed = utils::attrChanged($this->_changed, $this->remoteVersion, $_remoteVersion);
		$this->remoteVersion = $_remoteVersion;
		return $this;
	}

	public function getLogicalId() {
		return $this->logicalId;
	}

	public function setLogicalId($_logicalId) {
		$this->_changed = utils::attrChanged($this->_changed, $this->logicalId, $_logicalId);
		$this->logicalId = $_logicalId;
		return $this;
	}

	public function getSource() {
		return $this->source;
	}

	public function setSource($_source) {

		$this->_changed = utils::attrChanged($this->_changed, $this->source, $_source);
		$this->source = $_source;
		return $this;
	}

	public function getUpdateDate() {
		return $this->updateDate;
	}

	public function setUpdateDate($_updateDate) {
		$this->_changed = utils::attrChanged($this->_changed, $this->updateDate, $_updateDate);
		$this->updateDate = $_updateDate;
		return $this;
	}

	public function getChanged() {
		return $this->_changed;
	}

	public function setChanged($_changed) {
		$this->_changed = $_changed;
		return $this;
	}
}
