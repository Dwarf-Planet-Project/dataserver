<?
/*
    ***** BEGIN LICENSE BLOCK *****
    
    This file is part of the Zotero Data Server.
    
    Copyright © 2010 Center for History and New Media
                     George Mason University, Fairfax, Virginia, USA
                     http://zotero.org
    
    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.
    
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.
    
    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
    
    ***** END LICENSE BLOCK *****
*/

class Zotero_Item {
	private $id;
	private $libraryID;
	private $key;
	private $itemTypeID;
	private $dateAdded;
	private $dateModified;
	private $serverDateModified;
	private $serverDateModifiedMS;
	private $numNotes;
	private $numAttachments;
	
	private $itemData = array();
	private $creators = array();
	private $creatorSummary;
	
	private $sourceItem;
	private $noteTitle = null;
	private $noteText = null;
	
	private $deleted = null;
	
	private $attachmentData = array(
		'linkMode' => null,
		'mimeType' => null,
		'charset' => null,
		'storageModTime' => null,
		'storageHash' => null,
		'path' => null
	);
	
	private $relatedItems = array();
	
	// Populated by init()
	private $loaded = array();
	private $changed = array();
	private $previousData = array();
	
	public function __construct() {
		$numArgs = func_num_args();
		if ($numArgs) {
			throw new Exception("Constructor doesn't take any parameters");
		}
		
		$this->init();
	}
	
	
	private function init() {
		$this->loaded = array();
		$props = array(
			'primaryData',
			'itemData',
			'creators',
			'relatedItems'
		);
		foreach ($props as $prop) {
			$this->loaded[$prop] = false;
		}
		
		$this->changed = array();
		// Array
		$props = array(
			'primaryData',
			'itemData',
			'creators',
			'relatedItems',
			'attachmentData'
		);
		foreach ($props as $prop) {
			$this->changed[$prop] = array();
		}
		
		// Boolean
		$props = array(
			'deleted',
			'note',
			'source'
		);
		foreach ($props as $prop) {
			$this->changed[$prop] = false;
		}
		
		$this->previousData = array();
	}
	
	
	public function __get($field) {
		if ($field == 'id' || in_array($field, Zotero_Items::$primaryFields)) {
			if (!property_exists('Zotero_Item', $field)) {
				trigger_error("Zotero_Item property '$field' doesn't exist", E_USER_ERROR);
			}
			return $this->getField($field);
		}
		switch ($field) {
			case 'creatorSummary':
				return $this->getCreatorSummary();
				
			case 'deleted':
				return $this->getDeleted();
			
			case 'createdByUserID':
				return $this->getCreatedByUserID();
			
			case 'lastModifiedByUserID':
				return $this->getLastModifiedByUserID();
			
			case 'attachmentLinkMode':
				return $this->getAttachmentLinkMode();
				
			case 'attachmentMIMEType':
				return $this->getAttachmentMIMEType();
				
			case 'attachmentCharset':
				return $this->getAttachmentCharset();
			
			case 'attachmentPath':
				return $this->getAttachmentPath();
				
			case 'attachmentStorageModTime':
				return $this->getAttachmentStorageModTime();
			
			case 'attachmentStorageHash':
				return $this->getAttachmentStorageHash();
			
			case 'relatedItems':
				return $this->getRelatedItems();
			
			case 'etag':
				return $this->getETag();
		}
		
		trigger_error("'$field' is not a primary or attachment field", E_USER_ERROR);
	}
	
	
	public function __set($field, $val) {
		//Z_Core::debug("Setting field $field to '$val'");
		
		if ($field == 'id' || in_array($field, Zotero_Items::$primaryFields)) {
			if (!property_exists('Zotero_Item', $field)) {
				trigger_error("Zotero_Item property '$field' doesn't exist", E_USER_ERROR);
			}
			return $this->setField($field, $val);
		}
		
		switch ($field) {
			case 'deleted':
				return $this->setDeleted($val);
			
			case 'attachmentLinkMode':
				$this->setAttachmentField('linkMode', $val);
				return;
				
			case 'attachmentMIMEType':
				$this->setAttachmentField('mimeType', $val);
				return;
				
			case 'attachmentCharset':
				$this->setAttachmentField('charset', $val);
				return;
			
			case 'attachmentStorageModTime':
				$this->setAttachmentField('storageModTime', $val);
				return;
			
			case 'attachmentStorageHash':
				$this->setAttachmentField('storageHash', $val);
				return;
			
			case 'attachmentPath':
				$this->setAttachmentField('path', $val);
				return;
			
			case 'relatedItems':
				$this->setRelatedItems($val);
				return;
		}
		
		trigger_error("'$field' is not a valid Zotero_Item property", E_USER_ERROR);
	}
	
	
	public function getField($field, $unformatted=false, $includeBaseMapped=false, $skipValidation=false) {
		Z_Core::debug("Requesting field '$field' for item $this->id", 4);
		
		if (($this->id || $this->key) && !$this->loaded['primaryData']) {
			$this->loadPrimaryData(true);
		}
		
		if ($field == 'id' || in_array($field, Zotero_Items::$primaryFields)) {
			Z_Core::debug("Returning '{$this->$field}' for field $field", 4);
			
			return $this->$field;
		}
		if ($this->isNote()) {
			switch ($field) {
				case 'title':
					return $this->getNoteTitle();
				
				default:
					return '';
			}
		}
		
		if ($includeBaseMapped) {
			$fieldID = Zotero_ItemFields::getFieldIDFromTypeAndBase(
				$this->getField('itemTypeID'), $field
			);
		}
		
		if (empty($fieldID)) {
			$fieldID = Zotero_ItemFields::getID($field);
		}
		
		// If field is not valid for this (non-custom) type, return empty string
		if (!Zotero_ItemTypes::isCustomType($this->itemTypeID)
				&& !Zotero_ItemFields::isCustomField($fieldID)
				&& !array_key_exists($fieldID, $this->itemData)) {
			$msg = "Field '$field' doesn't exist for item $this->id of type {$this->itemTypeID}";
			if (!$skipValidation) {
				throw new Exception($msg);
			}
			Z_Core::debug($msg . "—returning ''", 4);
			return '';
		}
		
		if ($this->id && is_null($this->itemData[$fieldID]) && !$this->loaded['itemData']) {
			$this->loadItemData();
		}
		
		$value = $this->itemData[$fieldID] ? $this->itemData[$fieldID] : '';
		
        if (!$unformatted) {
			// Multipart date fields
			if (Zotero_ItemFields::isFieldOfBase($fieldID, 'date')) {
				$value = Zotero_Date::multipartToStr($value);
			}
		}
		
		Z_Core::debug("Returning '$value' for field $field", 4);
		return $value;
	}
	
	
	public function getDisplayTitle($includeAuthorAndDate=false) {
		$title = $this->getField('title', false, true);
		$itemTypeID = $this->itemTypeID;
		
		if (!$title && ($itemTypeID == 8 || $itemTypeID == 10)) { // 'letter' and 'interview' itemTypeIDs
			$creators = $this->getCreators();
			$authors = array();
			$participants = array();
			if ($creators) {
				foreach ($creators as $creator) {
					if (($itemTypeID == 8 && $creator['creatorTypeID'] == 16) || // 'letter'/'recipient'
							($itemTypeID == 10 && $creator['creatorTypeID'] == 7)) { // 'interview'/'interviewer'
						$participants[] = $creator;
					}
					else if (($itemTypeID == 8 && $creator['creatorTypeID'] == 1) ||   // 'letter'/'author'
							($itemTypeID == 10 && $creator['creatorTypeID'] == 6)) { // 'interview'/'interviewee'
						$authors[] = $creator;
					}
				}
			}
			
			$strParts = array();
			
			if ($includeAuthorAndDate) {
				$names = array();
				foreach($authors as $author) {
					$names[] = $author['ref']->lastName;
				}
				
				// TODO: Use same logic as getFirstCreatorSQL() (including "et al.")
				if ($names) {
					// TODO: was localeJoin() in client
					$strParts[] = implode(', ', $names);
				}
			}
			
			if ($participants) {
				$names = array();
				foreach ($participants as $participant) {
					$names[] = $participant['ref']->lastName;
				}
				switch (sizeOf($names)) {
					case 1:
						//$str = 'oneParticipant';
						$nameStr = $names[0];
						break;
						
					case 2:
						//$str = 'twoParticipants';
						$nameStr = "{$names[0]} and {$names[1]}";
						break;
						
					case 3:
						//$str = 'threeParticipants';
						$nameStr = "{$names[0]}, {$names[1]}, and {$names[2]}";
						break;
						
					default:
						//$str = 'manyParticipants';
						$nameStr = "{$names[0]} et al.";
				}
				
				/*
				pane.items.letter.oneParticipant		= Letter to %S
				pane.items.letter.twoParticipants		= Letter to %S and %S
				pane.items.letter.threeParticipants	= Letter to %S, %S, and %S
				pane.items.letter.manyParticipants		= Letter to %S et al.
				pane.items.interview.oneParticipant	= Interview by %S
				pane.items.interview.twoParticipants	= Interview by %S and %S
				pane.items.interview.threeParticipants	= Interview by %S, %S, and %S
				pane.items.interview.manyParticipants	= Interview by %S et al.
				*/
				
				//$strParts[] = Zotero.getString('pane.items.' + itemTypeName + '.' + str, names);
				
				$loc = Zotero_ItemTypes::getLocalizedString($itemTypeID);
				// Letter
				if ($itemTypeID == 8) {
					$loc .= ' to ';
				}
				// Interview
				else {
					$loc .= ' by ';
				}
				$strParts[] = $loc . $nameStr;
				
			}
			else {
				$strParts[] = Zotero_ItemTypes::getLocalizedString($itemTypeID);
			}
			
			if ($includeAuthorAndDate) {
				$d = $this->getField('date');
				if ($d) {
					$strParts[] = $d;
				}
			}
			
			$title = '[';
			$title .= join('; ', $strParts);
			$title .= ']';
		}
		else if ($itemTypeID == 17 && $title) { // 'case' itemTypeID
			$reporter = $this->getField('reporter');
			if ($reporter) {
				// TODO: was localeJoin() in client
				$title = $title . ' (' + $reporter + ')';
			}
		}
		
		return $title;
	}
	
	
	/**
	 * Returns all fields used in item
	 *
	 * @param	bool		$asNames		Return as field names
	 * @return	array				Array of field ids or names
	 */
	public function getUsedFields($asNames=false) {
		if (!$this->id) {
			return array();
		}
		
		$cacheKey = ($asNames ? "itemUsedFieldNames" : "itemUsedFieldIDs") . '_' . $this->id;
		
		$fields = Z_Core::$MC->get($cacheKey);
		$fields = false;
		if ($fields !== false) {
			return $fields;
		}
		
		$sql = "SELECT fieldID FROM itemData WHERE itemID=?";
		$fields = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$fields) {
			$fields = array();
		}
		
		if ($asNames) {
			$fieldNames = array();
			foreach ($fields as $field) {
				$fieldNames[] = Zotero_ItemFields::getName($field);
			}
			$fields = $fieldNames;
		}
		
		Z_Core::$MC->set($cacheKey, $fields);
		
		return $fields;
	}
	
	
	/**
	 * Check if item exists in the database
	 *
	 * @return	bool			TRUE if the item exists, FALSE if not
	 */
	public function exists() {
		if (!$this->id) {
			throw new Exception('$this->id not set');
		}
		
		$sql = "SELECT COUNT(*) FROM items WHERE itemID=?";
		return !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	private function load($allowFail=false) {
		$this->loadPrimaryData($allowFail);
		$this->loadItemData();
		$this->loadCreators();
	}
	
	
	private function loadPrimaryData($allowFail=false) {
		Z_Core::debug("Loading primary data for item $this->id");
		
		if ($this->loaded['primaryData']) {
			throw new Exception("Primary data already loaded for item $this->id");
		}
		
		$libraryID = $this->libraryID;
		$id = $this->id;
		$key = $this->key;
		
		if (!$libraryID) {
			throw new Exception("Library ID not set");
		}
		
		if (!$id && !$key) {
			throw new Exception("ID or key not set");
		}
		
		// Use cached check for existence if possible
		if ($libraryID && $key) {
			if (!Zotero_Items::existsByLibraryAndKey($libraryID, $key)) {
				$this->loaded['primaryData'] = true;
				
				if ($allowFail) {
					return false;
				}
				
				throw new Exception("Item " . ($id ? $id : "$libraryID/$key") . " not found");
			}
		}
		
		$columns = array();
		foreach (Zotero_Items::$primaryFields as $field) {
			$colSQL = '';
			if (is_null($field == 'itemID' ? $this->id : $this->$field)) {
				switch ($field) {
					case 'itemID':
					case 'itemTypeID':
					case 'dateAdded':
					case 'dateModified':
					case 'libraryID':
					case 'key':
					case 'serverDateModified':
						$colSQL = 'I.' . $field;
						break;
					
					case 'numNotes':
						$colSQL = '(SELECT COUNT(*) FROM itemNotes INo
							WHERE sourceItemID=I.itemID AND INo.itemID NOT IN
							(SELECT itemID FROM deletedItems)) AS numNotes';
						break;
						
					case 'numAttachments':
						$colSQL = '(SELECT COUNT(*) FROM itemAttachments IA
							WHERE sourceItemID=I.itemID AND IA.itemID NOT IN
							(SELECT itemID FROM deletedItems)) AS numAttachments';
						break;
					
					case 'numNotes':
						$colSQL = '(SELECT COUNT(*) FROM itemNotes
									WHERE sourceItemID=I.itemID) AS numNotes';
						break;
						
					case 'numAttachments':
						$colSQL = '(SELECT COUNT(*) FROM itemAttachments
									WHERE sourceItemID=I.itemID) AS numAttachments';
						break;
				}
				if ($colSQL) {
					$columns[] = $colSQL;
				}
			}
		}
		
		$sql = 'SELECT ' . implode(', ', $columns) . " FROM items I WHERE ";
		
		if ($id) {
			if (!is_numeric($id)) {
				trigger_error("Invalid itemID '$id'", E_USER_ERROR);
			}
			$sql .= "itemID=?";
			$stmt = Zotero_DB::getStatement($sql, 'loadPrimaryData_id', Zotero_Shards::getByLibraryID($libraryID));
			$row = Zotero_DB::rowQueryFromStatement($stmt, array($id));
		}
		else {
			if (!is_numeric($libraryID)) {
				trigger_error("Invalid libraryID '$libraryID'", E_USER_ERROR);
			}
			if (!preg_match('/[A-Z0-9]{8}/', $key)) {
				trigger_error("Invalid key '$key'!", E_USER_ERROR);
			}
			$sql .= "libraryID=? AND `key`=?";
			$stmt = Zotero_DB::getStatement($sql, 'loadPrimaryData_key', Zotero_Shards::getByLibraryID($libraryID));
			$row = Zotero_DB::rowQueryFromStatement($stmt, array($libraryID, $key));
		}
		
		$this->loaded['primaryData'] = true;
		
		if (!$row) {
			if ($allowFail) {
				return false;
			}
			throw new Exception("Item " . ($id ? $id : "$libraryID/$key") . " not found");
		}
		
		$this->loadFromRow($row);
		
		return true;
	}
	
	
	public function loadFromRow($row, $reload=false) {
		if ($reload) {
			$this->init();
		}
		
		// If necessary or reloading, set the type and reinitialize $this->itemData
		if ($reload || (!$this->itemTypeID && $row['itemTypeID'])) {
			$this->setType($row['itemTypeID'], true);
		}
		
		foreach ($row as $field=>$val) {
			// Only accept primary field data through loadFromRow()
			if (in_array($field, Zotero_Items::$primaryFields)) {
				//Z_Core::debug("Setting field '" + col + "' to '" + row[col] + "' for item " + this.id);
				switch ($field) {
					case 'itemID':
						$this->id = $val;
						break;
						
					case 'itemTypeID':
						$this->setType($val, true);
						break;
					
					default:
						$this->$field = $val;
				}
			}
			else {
				Z_Core::debug("'$field' is not a valid primary field", 1);
			}
		}
		
		$this->loaded['primaryData'] = true;
	}
	
	
	private function setType($itemTypeID, $loadIn=false) {
		if ($this->itemTypeID == $itemTypeID) {
			return false;
		}
		
		// TODO: block switching to/from note or attachment
		
		if (!Zotero_ItemTypes::getID($itemTypeID)) {
			throw new Exception("Invalid itemTypeID", Z_ERROR_INVALID_INPUT);
		}
		
		$copiedFields = array();
		
		// If there's an existing type
		if ($this->itemTypeID) {
			$obsoleteFields = $this->getFieldsNotInType($itemTypeID);
			if ($obsoleteFields) {
				foreach($obsoleteFields as $oldFieldID) {
					// Try to get a base type for this field
					$baseFieldID =
						Zotero_ItemFields::getBaseIDFromTypeAndField($this->itemTypeID, $oldFieldID);
					
					if ($baseFieldID) {
						$newFieldID =
							Zotero_ItemFields::getFieldIDFromTypeAndBase($itemTypeID, $baseFieldID);
						
						// If so, save value to copy to new field
						if ($newFieldID) {
							$copiedFields[] = array($newFieldID, $this->getField($oldFieldID));
						}
					}
					
					// Clear old field
					$this->setField($oldFieldID, false);
				}
			}
			
			if (!$loadIn) {
				foreach ($this->itemData as $fieldID=>$value) {
					if ($this->itemData[$fieldID] && // why?
							(!$obsoleteFields || !in_array($fieldID, $obsoleteFields))) {
						$copiedFields[] = array($fieldID, $this->getField($fieldID));
					}
				}
			}
			
			// And reset custom creator types to the default
			$creators = $this->getCreators();
			if ($creators) {
				foreach ($creators as $orderIndex=>$creator) {
					if (Zotero_CreatorTypes::isCustomType($creator['creatorTypeID'])) {
						continue;
					}
					if (!Zotero_CreatorTypes::isValidForItemType($creator['creatorTypeID'], $itemTypeID)) {
						// TODO: port
						
						// Reset to contributor (creatorTypeID 2), which exists in all
						$this->setCreator($orderIndex, $creator['ref'], 2);
					}
				}
			}
		}
		
		$this->itemTypeID = $itemTypeID;
		
		// If not custom item type, initialize $this->itemData with type-specific fields
		$this->itemData = array();
		if (!Zotero_ItemTypes::isCustomType($itemTypeID)) {
			$fields = Zotero_ItemFields::getItemTypeFields($itemTypeID);
			foreach($fields as $fieldID) {
				$this->itemData[$fieldID] = null;
			}
		}
		
		if ($copiedFields) {
			foreach($copiedFields as $copiedField) {
				$this->setField($copiedField[0], $copiedField[1]);
			}
		}
		
		if ($loadIn) {
			$this->loaded['itemData'] = false;
		}
		else {
			$this->changed['primaryData']['itemTypeID'] = true;
		}
		
		return true;
	}
	
	
	/*
	 * Find existing fields from current type that aren't in another
	 *
	 * If _allowBaseConversion_, don't return fields that can be converted
	 * via base fields (e.g. label => publisher => studio)
	 */
	private function getFieldsNotInType($itemTypeID, $allowBaseConversion=false) {
		$usedFields = self::getUsedFields();
		if (!$usedFields) {
			return false;
		}
		
		$sql = "SELECT fieldID FROM itemTypeFields
				WHERE itemTypeID=? AND fieldID IN ("
				. implode(', ', array_fill(0, sizeOf($usedFields), '?'))
				. ") AND
				fieldID NOT IN (SELECT fieldID FROM itemTypeFields WHERE itemTypeID=?)";
		
		if ($allowBaseConversion) {
			trigger_error("Unimplemented", E_USER_ERROR);
			/*
			// Not the type-specific field for a base field in the new type
			sql += " AND fieldID NOT IN (SELECT fieldID FROM baseFieldMappings "
				+ "WHERE itemTypeID=?1 AND baseFieldID IN "
				+ "(SELECT fieldID FROM itemTypeFields WHERE itemTypeID=?3)) AND ";
			// And not a base field with a type-specific field in the new type
			sql += "fieldID NOT IN (SELECT baseFieldID FROM baseFieldMappings "
				+ "WHERE itemTypeID=?3) AND ";
			// And not the type-specific field for a base field that has
			// a type-specific field in the new type
			sql += "fieldID NOT IN (SELECT fieldID FROM baseFieldMappings "
				+ "WHERE itemTypeID=?1 AND baseFieldID IN "
				+ "(SELECT baseFieldID FROM baseFieldMappings WHERE itemTypeID=?3))";
			*/
		}
		
		return Zotero_DB::columnQuery(
			$sql,
			array_merge(array($this->itemTypeID), $usedFields, array($itemTypeID))
		);
	}
	
	
	
	/**
	 * @param 	string|int	$field				Field name or ID
	 * @param	mixed		$value				Field value
	 * @param	bool		$loadIn				Populate the data fields without marking as changed
	 */
	public function setField($field, $value, $loadIn=false) {
		if (empty($field)) {
			trigger_error("Field not specified", E_USER_ERROR);
		}
		
		// Set id, libraryID, and key without loading data first
		switch ($field) {
			case 'id':
			case 'libraryID':
			case 'key':
				if ($this->loaded['primaryData']) {
					throw new Exception("Cannot set $field after item is already loaded");
				}
				//this._checkValue(field, val);
				$this->$field = $value;
				return;
		}
		
		if ($this->id || $this->key) {
			if (!$this->loaded['primaryData']) {
				$this->loadPrimaryData(true);
			}
		}
		else {
			$this->loaded['primaryData'] = true;
		}
		
		// Primary field
		if (in_array($field, Zotero_Items::$primaryFields)) {
			if ($loadIn) {
				throw new Exception("Cannot set primary field $field in loadIn mode");
			}
			
			switch ($field) {
				case 'itemID':
				case 'serverDateModified':
				case 'numNotes':
				case 'numAttachments':
					trigger_error("Primary field '$field' cannot be changed through setField()", E_USER_ERROR);
			}
			
			if (!Zotero_ItemFields::validate($field, $value)) {
				trigger_error("Value '$value' of type " . gettype($value) . " does not validate for field '$field'", E_USER_ERROR);
			}
			
			if ($this->$field != $value) {
				Z_Core::debug("Field $field has changed from {$this->$field} to $value", 4);
				
				if ($field == 'itemTypeID') {
					$this->setType($value, $loadIn);
				}
				else {
					$this->$field = $value;
					$this->changed['primaryData'][$field] = true;
				}
			}
			
			return true;
		}
		
		//
		// itemData field
		//
		
		if (!$this->itemTypeID) {
			trigger_error('Item type must be set before setting field data', E_USER_ERROR);
		}
		
		// If existing item, load field data first unless we're already in
		// the middle of a load
		if ($this->id) {
			if (!$loadIn && !$this->loaded['itemData']) {
				$this->loadItemData();
			}
		}
		else {
			$this->loaded['itemData'] = true;
		}
		
		$fieldID = Zotero_ItemFields::getID($field);
		
		if (!$fieldID) {
			throw new Exception("'$field' is not a valid itemData field.", Z_ERROR_INVALID_INPUT);
		}
		
		if ($value && !Zotero_ItemFields::isValidForType($fieldID, $this->itemTypeID)) {
			throw new Exception("'$field' is not a valid field for type '"
				. Zotero_ItemTypes::getName($this->itemTypeID), Z_ERROR_INVALID_INPUT);
		}
		
		if (!$loadIn) {
			// TODO: port
			/*
			// Save date field as multipart date
			if (Zotero_ItemFields::isFieldOfBase(fieldID, 'date') &&
					!Zotero.Date.isMultipart(value)) {
				value = Zotero.Date.strToMultipart(value);
			}
			// Validate access date
			else if (fieldID == Zotero.ItemFields.getID('accessDate')) {
				if (value && (!Zotero.Date.isSQLDate(value) &&
						!Zotero.Date.isSQLDateTime(value) &&
						value != 'CURRENT_TIMESTAMP')) {
					Z_Core::debug("Discarding invalid accessDate '" + value
						+ "' in Item.setField()");
					return false;
				}
			}
			*/
			
			// If existing value, make sure it's actually changing
			if (!$loadIn &&
					(!isset($this->itemData[$fieldID]) && !$value) ||
					(isset($this->itemData[$fieldID]) && $this->itemData[$fieldID] == $value)) {
				return false;
			}
			
			// TODO: Save a copy of the object before modifying?
		}
		
		$this->itemData[$fieldID] = $value;
		
		if (!$loadIn) {
			$this->changed['itemData'][$fieldID] = true;
		}
		return true;
	}
	
	
	public function isNote() {
		return Zotero_ItemTypes::getName($this->getField('itemTypeID')) == 'note';
	}
	
	
	public function isAttachment() {
		return Zotero_ItemTypes::getName($this->getField('itemTypeID')) == 'attachment';
	}
	
	
	public function isImportedAttachment() {
		if (!$this->isAttachment()) {
			return false;
		}
		$linkMode = $this->attachmentLinkMode;
		// TODO: get from somewhere
		return $linkMode == 0 || $linkMode == 1;
	}
	
	
	public function hasChanged() {
		foreach ($this->changed as $changed) {
			if ($changed) {
				return true;
			}
		}
		return false;
	}
	
	
	public function getCreatorSummary() {
		if ($this->creatorSummary !== null) {
			return $this->creatorSummary;
		}
		
		// TODO: memcache
		
		$itemTypeID = $this->getField('itemTypeID');
		$creators = $this->getCreators();
		
		$creatorTypeIDsToTry = array(
			// First try for primary creator types
			Zotero_CreatorTypes::getPrimaryIDForType($itemTypeID),
			// Then try editors
			Zotero_CreatorTypes::getID('editor'),
			// Then try contributors
			Zotero_CreatorTypes::getID('contributor')
		);
		
		$localizedAnd = " and ";
		$etAl = " et al.";
		
		foreach ($creatorTypeIDsToTry as $creatorTypeID) {
			$loc = array();
			foreach ($creators as $orderIndex=>$creator) {
				if ($creator['creatorTypeID'] == $creatorTypeID) {
					$loc[] = $orderIndex;
					
					if (sizeOf($loc) == 3) {
						break;
					}
				}
			}
			
			switch (sizeOf($loc)) {
				case 0:
					continue 2;
				
				case 1:
					$creatorSummary = $creators[$loc[0]]['ref']->lastName;
					break;
				
				case 2:
					$creatorSummary = $creators[$loc[0]]['ref']->lastName
							. $localizedAnd
							. $creators[$loc[1]]['ref']->lastName;
					break;
				
				case 3:
					$creatorSummary = $creators[$loc[0]]['ref']->lastName . $etAl;
					break;
			}
			
			$this->creatorSummary = $creatorSummary;
			return $this->creatorSummary;
		}
		
		$this->creatorSummary = '';
		return '';
	}
	
	
	private function getDeleted() {
		if ($this->deleted !== null) {
			return $this->deleted;
		}
		
		if (!$this->__get('id')) {
			return false;
		}
		
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$cacheKey = "itemIsDeleted_" . $this->id;
		$deleted = Z_Core::$MC->get($cacheKey);
		$deleted = false;
		if ($deleted !== false) {
			$deleted = !!$deleted;
			$this->deleted = $deleted;
			return $deleted;
		}
		
		$sql = "SELECT COUNT(*) FROM deletedItems WHERE itemID=?";
		$deleted = !!Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->deleted = $deleted;
		
		// Memcache returns false for empty keys, so use integers
		Z_Core::$MC->set($cacheKey, $deleted ? 1 : 0);
		
		return $deleted;
	}
	
	
	private function setDeleted($val) {
		$deleted = !!$val;
		
		if ($this->getDeleted() == $deleted) {
			Z_Core::debug("Deleted state ($deleted) hasn't changed for item $this->id");
			return;
		}
		
		// TEMP: deleted="1" is being sent for some group items
		if ($val && Zotero_Libraries::getType($this->libraryID) == 'group') {
			Z_Core::logError("Deleted flag set for group library item -- ignoring");
			return;
		}
		
		if (!$this->changed['deleted']) {
			$this->changed['deleted'] = true;
		}
		$this->deleted = $deleted;
	}
	
	
	private function getCreatedByUserID() {
		$sql = "SELECT createdByUserID FROM groupItems WHERE itemID=?";
		return Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	private function getLastModifiedByUserID() {
		$sql = "SELECT lastModifiedByUserID FROM groupItems WHERE itemID=?";
		return Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	public function addRelatedItem($itemID) {
		if ($itemID == $this->id) {
			Z_Core::debug("Can't relate item to itself in Zotero_Item.addRelatedItem()", 2);
			return false;
		}
		
		$current = $this->getRelatedItems();
		if ($current && in_array($itemID, $current)) {
			Z_Core::debug("Item $this->id already related to
				item $itemID in Zotero_Item.addItem()");
			return false;
		}
		
		$item = Zotero_Items::get($this->libraryID, $itemID);
		if (!$item) {
			trigger_error("Can't relate item to invalid item $itemID
				in Zotero.Item.addRelatedItem()", E_USER_ERROR);
		}
		$otherCurrent = $item->relatedItems;
		if ($otherCurrent && in_array($this->id, $otherCurrent)) {
			Z_Core::debug("Other item $itemID already related to item
				$this->id in Zotero_Item.addItem()");
			return false;
		}
		
		$this->storePreviousData('relatedItems');
		$this->changed['relatedItems'] = true;
		$this->relatedItems[] = $itemID;
		return true;
	}
	
	
	public function removeRelatedItem($itemID) {
		$current = $this->getRelatedItems();
		if ($current) {
			$index = array_search($itemID, $current);
		}
		
		if (!$current || $index === false) {
			Z_Core::debug("Item $this->id isn't related to item $itemID
				in Zotero_Item.removeRelatedItem()");
			return false;
		}
		
		$this->storePreviousData('relatedItems');
		$this->changed['relatedItems'] = true;
		unset($this->relatedItems[$index]);
		return true;
	}
	
	
	public function save($userID=false) {
		if (!$this->libraryID) {
			trigger_error("Library ID must be set before saving", E_USER_ERROR);
		}
		
		Zotero_Items::editCheck($this);
		
		if (!$this->hasChanged()) {
			Z_Core::debug("Item $this->id has not changed");
			return false;
		}
		
		// Make sure there are no gaps in the creator indexes
		$creators = $this->getCreators();
		$lastPos = -1;
		foreach ($creators as $pos=>$creator) {
			if ($pos != $lastPos + 1) {
				trigger_error("Creator index $pos out of sequence for item $this->id", E_USER_ERROR);
			}
			$lastPos++;
		}
		
		$shardID = Zotero_Shards::getByLibraryID($this->libraryID);
		
		Zotero_DB::beginTransaction();
		
		try {
			//
			// New item, insert and return id
			//
			if (!$this->id || !$this->exists()) {
				Z_Core::debug('Saving data for new item to database');
				
				$isNew = true;
				$sqlColumns = array();
				$sqlValues = array();
				
				//
				// Primary fields
				//
				$itemID = $this->id ? $this->id : Zotero_ID::get('items');
				$key = $this->key ? $this->key : $this->generateKey();
				
				$sqlColumns = array(
					'itemID',
					'itemTypeID',
					'libraryID',
					'key',
					'dateAdded',
					'dateModified',
					'serverDateModified',
					'serverDateModifiedMS'
				);
				$timestamp = Zotero_DB::getTransactionTimestamp();
				$timestampMS = Zotero_DB::getTransactionTimestampMS();
				$sqlValues = array(
					$itemID,
					$this->itemTypeID,
					$this->libraryID,
					$key,
					$this->dateAdded ? $this->dateAdded : $timestamp,
					$this->dateModified ? $this->dateModified : $timestamp,
					$timestamp,
					$timestampMS
				);
				
				//
				// Primary fields
				//
				$sql = 'INSERT INTO items (`' . implode('`, `', $sqlColumns) . '`) VALUES (';
				// Insert placeholders for bind parameters
				for ($i=0; $i<sizeOf($sqlValues); $i++) {
					$sql .= '?, ';
				}
				$sql = substr($sql, 0, -2) . ')';
				
				// Save basic data to items table
				$insertID = Zotero_DB::query($sql, $sqlValues, $shardID);
				if (!$this->id) {
					if (!$insertID) {
						throw new Exception("Item id not available after INSERT");
					}
					$itemID = $insertID;
					Zotero_Items::cacheLibraryKeyID($this->libraryID, $key, $insertID);
					
					$this->serverDateModified = $timestamp;
					$this->serverDateModifiedMS = $timestampMS;
				}
				
				// Group item data
				if (Zotero_Libraries::getType($this->libraryID) == 'group' && $userID) {
					$sql = "INSERT INTO groupItems VALUES (?, ?, ?)";
					Zotero_DB::query($sql, array($itemID, $userID, null), $shardID);
				}
				
				//
				// ItemData
				//
				if ($this->changed['itemData']) {
					// Use manual bound parameters to speed things up
					$origInsertSQL = "INSERT INTO itemData VALUES ";
					$insertSQL = $origInsertSQL;
					$insertParams = array();
					$insertCounter = 0;
					$maxInsertGroups = 40;
					
					$fieldIDs = array_keys($this->changed['itemData']);
					
					foreach ($fieldIDs as $fieldID) {
						$value = $this->getField($fieldID, true, false, true);
						
						if ($value == 'CURRENT_TIMESTAMP'
								&& Zotero_ItemFields::getID('accessDate') == $fieldID) {
							$value = Zotero_DB::getTransactionTimestamp();
						}
						
						try {
							$hash = Zotero_Items::getDataValueHash($value, true);
						}
						catch (Exception $e) {
							$msg = $e->getMessage();
							if (strpos($msg, "Data too long for column 'value'") !== false) {
								$fieldName = Zotero_ItemFields::getLocalizedString(
									$this->itemTypeID, $fieldID
								);
								throw new Exception("=$fieldName field " .
									 "'" . substr($value, 0, 50) . "...' too long");
							}
							throw ($e);
						}
						
						if ($insertCounter < $maxInsertGroups) {
							$insertSQL .= "(?,?,?),";
							$insertParams = array_merge(
								$insertParams,
								array($itemID, $fieldID, $hash)
							);
						}
						
						if ($insertCounter == $maxInsertGroups - 1) {
							$insertSQL = substr($insertSQL, 0, -1);
							$stmt = Zotero_DB::getStatement($insertSQL, true, $shardID);
							Zotero_DB::queryFromStatement($stmt, $insertParams);
							$insertSQL = $origInsertSQL;
							$insertParams = array();
							$insertCounter = -1;
						}
						
						$insertCounter++;
					}
					
					if ($insertCounter > 0 && $insertCounter < $maxInsertGroups) {
						$insertSQL = substr($insertSQL, 0, -1);
						$stmt = Zotero_DB::getStatement($insertSQL, true, $shardID);
						Zotero_DB::queryFromStatement($stmt, $insertParams);
					}
					
					// Update memcached with used fields
					Z_Core::$MC->set("itemUsedFieldIDs_" . $itemID, $fieldIDs);
					$names = array();
					foreach ($fieldIDs as $fieldID) {
						$names[] = Zotero_ItemFields::getName($fieldID);
					}
					Z_Core::$MC->set("itemUsedFieldNames_" . $itemID, $names);
				}
				
				
				//
				// Creators
				//
				if ($this->changed['creators']) {
					$indexes = array_keys($this->changed['creators']);
					
					// TODO: group queries
					
					$sql = "INSERT INTO itemCreators
								(itemID, creatorID, creatorTypeID, orderIndex) VALUES ";
					$placeholders = array();
					$sqlValues = array();
					
					$cacheRows = array();
					
					foreach ($indexes as $orderIndex) {
						Z_Core::debug('Adding creator in position ' . $orderIndex, 4);
						$creator = $this->getCreator($orderIndex);
						
						if (!$creator) {
							continue;
						}
						
						if ($creator['ref']->hasChanged()) {
							Z_Core::debug("Auto-saving changed creator {$creator['ref']->id}");
							$creator['ref']->save();
						}
						
						$placeholders[] = "(?, ?, ?, ?)";
						array_push(
							$sqlValues,
							$itemID,
							$creator['ref']->id,
							$creator['creatorTypeID'],
							$orderIndex
						);
						
						$cacheRows[] = array(
							'creatorID' => $creator['ref']->id,
							'creatorTypeID' => $creator['creatorTypeID'],
							'orderIndex' => $orderIndex
						);
					}
					
					if ($sqlValues) {
						$sql = $sql . implode(',', $placeholders);
						Zotero_DB::query($sql, $sqlValues, $shardID);
					}
					
					// Just in case creators aren't in order
					usort($cacheRows, function ($a, $b) {
						return ($a['orderIndex'] < $b['orderIndex']) ? -1 : 1; 
					});
					Z_Core::$MC->set("itemCreators_" . $itemID, $cacheRows);
				}
				
				
				// Deleted item
				if ($this->changed['deleted']) {
					$deleted = $this->getDeleted();
					if ($deleted) {
						$sql = "REPLACE INTO deletedItems (itemID) VALUES (?)";
					}
					else {
						$sql = "DELETE FROM deletedItems WHERE itemID=?";
					}
					Zotero_DB::query($sql, $itemID, $shardID);
					$deleted = Z_Core::$MC->set("itemIsDeleted_" . $itemID, $deleted ? 1 : 0);
				}
				
				
				// Note
				if ($this->isNote() || $this->changed['note']) {
					$title = Zotero_Notes::noteToTitle($this->noteText);
					
					$sql = "INSERT INTO itemNotes
							(itemID, sourceItemID, note, title, hash) VALUES
							(?,?,?,?,?)";
					$parent = $this->isNote() ? $this->getSource() : null;
					$noteText = $this->noteText ? $this->noteText : '';
					$hash = $noteText ? md5($noteText) : '';
					$bindParams = array(
						$itemID,
						$parent ? $parent : null,
						$noteText,
						$title,
						$hash
					);
					
					Zotero_DB::query($sql, $bindParams, $shardID);
					Zotero_Notes::updateNoteCache($this->libraryID, $itemID, $noteText);
					Zotero_Notes::updateHash($this->libraryID, $itemID, $hash);
				}
				
				
				// Attachment
				if ($this->isAttachment()) {
					$sql = "INSERT INTO itemAttachments
							(itemID, sourceItemID, linkMode, mimeType, charsetID, path, storageModTime, storageHash)
							VALUES (?,?,?,?,?,?,?,?)";
					$parent = $this->getSource();
					if ($parent) {
						$parentItem = Zotero_Items::get($this->libraryID, $parent);
						if (!$parentItem) {
							throw new Exception("Parent item $parent not found");
						}
						if ($parentItem->getSource()) {
							trigger_error("Parent item cannot be a child attachment", E_USER_ERROR);
						}
					}
					
					$linkMode = $this->attachmentLinkMode;
					$charsetID = Zotero_CharacterSets::getID($this->attachmentCharset);
					$path = $this->attachmentPath;
					$storageModTime = $this->attachmentStorageModTime;
					$storageHash = $this->attachmentStorageHash;
					
					$bindParams = array(
						$itemID,
						$parent ? $parent : null,
						$linkMode + 1,
						$this->attachmentMIMEType,
						$charsetID ? $charsetID : null,
						$path ? $path : '',
						$storageModTime ? $storageModTime : null,
						$storageHash ? $storageHash : null
					);
					Zotero_DB::query($sql, $bindParams, $shardID);
				}
				
				
				//
				// Source item id
				//
				if (false && $this->getSource()) {
					trigger_error("Unimplemented", E_USER_ERROR);
					// NOTE: don't need much of this on insert
					
					$newItem = Zotero_Items::get($this->libraryID, $sourceItemID);
					// FK check
					if ($newItem) {
						if ($sourceItemID) {
						}
						else {
							trigger_error("Cannot set $type source to invalid item $sourceItemID", E_USER_ERROR);
						}
					}
					
					$oldSourceItemID = $this->getSource();
					
					if ($oldSourceItemID == $sourceItemID) {
						Z_Core::debug("$Type source hasn't changed", 4);
					}
					else {
						$oldItem = Zotero_Items::get($this->libraryID, $oldSourceItemID);
						if ($oldSourceItemID && $oldItem) {
						}
						else {
							//$oldItemNotifierData = null;
							Z_Core::debug("Old source item $oldSourceItemID didn't exist in setSource()", 2);
						}
						
						// If this was an independent item, remove from any collections where it
						// existed previously and add source instead if there is one
						if (!$oldSourceItemID) {
							$sql = "SELECT collectionID FROM collectionItems WHERE itemID=?";
							$changedCollections = Zotero_DB::query($sql, $itemID, $shardID);
							if ($changedCollections) {
								trigger_error("Unimplemented", E_USER_ERROR);
								if ($sourceItemID) {
									$sql = "UPDATE OR REPLACE collectionItems "
										. "SET itemID=? WHERE itemID=?";
									Zotero_DB::query($sql, array($sourceItemID, $this->id), $shardID);
								}
								else {
									$sql = "DELETE FROM collectionItems WHERE itemID=?";
									Zotero_DB::query($sql, $this->id, $shardID);
								}
							}
						}
						
						$sql = "UPDATE item{$Type}s SET sourceItemID=?
								WHERE itemID=?";
						$bindParams = array(
							$sourceItemID ? $sourceItemID : null,
							$itemID
						);
						Zotero_DB::query($sql, $bindParams, $shardID);
						
						//Zotero.Notifier.trigger('modify', 'item', $this->id, notifierData);
						
						// Update the counts of the previous and new sources
						if ($oldItem) {
							/*
							switch ($type) {
								case 'note':
									$oldItem->decrementNoteCount();
									break;
								case 'attachment':
									$oldItem->decrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', oldSourceItemID, oldItemNotifierData);
						}
						
						if ($newItem) {
							/*
							switch ($type) {
								case 'note':
									$newItem->incrementNoteCount();
									break;
								case 'attachment':
									$newItem->incrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', sourceItemID, newItemNotifierData);
						}
					}
				}
				
 				
				// Related items
				if (!empty($this->changed['relatedItems'])) {
					$removed = array();
					$newids = array();
					$currentIDs = $this->relatedItems;
					
					if (!$currentIDs) {
						$currentIDs = array();
					}
					
					if ($this->previousData['relatedItems']) {
						foreach($this->previousData['relatedItems'] as $id) {
							if (!in_array($id, $currentIDs)) {
								$removed[] = $id;
							}
						}
					}
					
					foreach ($currentIDs as $id) {
						if ($this->previousData['relatedItems'] &&
								in_array($id, $this->previousData['relatedItems'])) {
							continue;
						}
						$newids[] = $id;
					}
					
					if ($removed) {
						$sql = "DELETE FROM itemRelated WHERE itemID=?
								AND linkedItemID IN (";
						$sql .= implode(', ', array_fill(0, sizeOf($removed), '?')) . ")";
						Zotero_DB::query(
							$sql,
							array_merge(array($this->id), $removed),
							$shardID
						);
					}
					
					if ($newids) {
						$sql = "INSERT INTO itemRelated (itemID, linkedItemID)
								VALUES (?,?)";
						$insertStatement = Zotero_DB::getStatement($sql, false, $shardID);
						
						foreach ($newids as $linkedItemID) {
							$insertStatement->execute(array($itemID, $linkedItemID));
						}
					}
					
					Z_Core::$MC->set("itemRelated_" . $itemID, $currentIDs);
				}
			}
			
			//
			// Existing item, update
			//
			else {
				Z_Core::debug('Updating database with new item data', 4);
				
				$isNew = false;
				
				//
				// Primary fields
				//
				$sql = "UPDATE items SET ";
				$sqlValues = array();
				
				$timestamp = Zotero_DB::getTransactionTimestamp();
				$timestampMS = Zotero_DB::getTransactionTimestampMS();
				
				$updateFields = array(
					'itemTypeID',
					'libraryID',
					'key',
					'dateAdded',
					'dateModified'
				);
				
				foreach ($updateFields as $updateField) {
					if (in_array($updateField, $this->changed['primaryData'])) {
						$sql .= "`$updateField`=?, ";
						$sqlValues[] = $this->$updateField;
					}
					else if ($updateField == 'dateModified') {
						$sql .= "`$updateField`=?, ";
						$sqlValues[] = $timestamp;
					}
				}
				
				$sql .= "serverDateModified=?, serverDateModifiedMS=? WHERE itemID=?";
				array_push(
					$sqlValues,
					$timestamp,
					$timestampMS,
					$this->id
				);
				
				Zotero_DB::query($sql, $sqlValues, $shardID);
				
				$this->serverDateModified = $timestamp;
				$this->serverDateModifiedMS = $timestampMS;
				
				// Group item data
				if (Zotero_Libraries::getType($this->libraryID) == 'group' && $userID) {
					$sql = "INSERT INTO groupItems VALUES (?, ?, ?)
								ON DUPLICATE KEY UPDATE lastModifiedByUserID=?";
					Zotero_DB::query($sql, array($this->id, null, $userID, $userID), $shardID);
				}
				
				
				//
				// ItemData
				//
				if ($this->changed['itemData']) {
					$del = array();
					
					$origReplaceSQL = "REPLACE INTO itemData VALUES ";
					$replaceSQL = $origReplaceSQL;
					$replaceParams = array();
					$replaceCounter = 0;
					$maxReplaceGroups = 40;
					
					$fieldIDs = array_keys($this->changed['itemData']);
					
					foreach ($fieldIDs as $fieldID) {
						$value = $this->getField($fieldID, true, false, true);
						
						// If field changed and is empty, mark row for deletion
						if (!$value) {
							$del[] = $fieldID;
							continue;
						}
						
						if ($value == 'CURRENT_TIMESTAMP'
								&& Zotero_ItemFields::getID('accessDate') == $fieldID) {
							$value = Zotero_DB::getTransactionTimestamp();
						}
						
						try {
							$hash = Zotero_Items::getDataValueHash($value, true);
						}
						catch (Exception $e) {
							$msg = $e->getMessage();
							if (strpos($msg, "Data too long for column 'value'") !== false) {
								$fieldName = Zotero_ItemFields::getLocalizedString(
									$this->itemTypeID, $fieldID
								);
								throw new Exception("=$fieldName field " .
									 "'" . substr($value, 0, 50) . "...' too long");
							}
							throw ($e);
						}
						
						if ($replaceCounter < $maxReplaceGroups) {
							$replaceSQL .= "(?,?,?),";
							$replaceParams = array_merge($replaceParams,
								array($this->id, $fieldID, $hash)
							);
						}
						
						if ($replaceCounter == $maxReplaceGroups - 1) {
							$replaceSQL = substr($replaceSQL, 0, -1);
							$stmt = Zotero_DB::getStatement($replaceSQL, true, $shardID);
							Zotero_DB::queryFromStatement($stmt, $replaceParams);
							$replaceSQL = $origReplaceSQL;
							$replaceParams = array();
							$replaceCounter = -1;
						}
						$replaceCounter++;
					}
					
					if ($replaceCounter > 0 && $replaceCounter < $maxReplaceGroups) {
						$replaceSQL = substr($replaceSQL, 0, -1);
						$stmt = Zotero_DB::getStatement($replaceSQL, true, $shardID);
						Zotero_DB::queryFromStatement($stmt, $replaceParams);
					}
					
					// Update memcached with used fields
					$fids = array();
					foreach ($this->itemData as $fieldID=>$value) {
						if ($value !== false && $value !== null) {
							$fids[] = $fieldID;
						}
					}
					Z_Core::$MC->set("itemUsedFieldIDs_" . $this->id, $fids);
					$names = array();
					foreach ($fids as $fieldID) {
						$names[] = Zotero_ItemFields::getName($fieldID);
					}
					Z_Core::$MC->set("itemUsedFieldNames_" . $this->id, $names);
					
					// Delete blank fields
					if ($del) {
						$sql = 'DELETE from itemData WHERE itemID=? AND fieldID IN (';
						$sqlParams = array($this->id);
						foreach ($del as $d) {
							$sql .= '?, ';
							$sqlParams[] = $d;
						}
						$sql = substr($sql, 0, -2) . ')';
						
						Zotero_DB::query($sql, $sqlParams, $shardID);
					}
				}
				
				//
				// Creators
				//
				if ($this->changed['creators']) {
					$indexes = array_keys($this->changed['creators']);
					
					$sql = "INSERT INTO itemCreators
								(itemID, creatorID, creatorTypeID, orderIndex) VALUES ";
					$placeholders = array();
					$sqlValues = array();
					
					$cacheRows = array();
					
					foreach ($indexes as $orderIndex) {
						Z_Core::debug('Creator in position ' . $orderIndex . ' has changed', 4);
						$creator = $this->getCreator($orderIndex);
						
						$sql2 = 'DELETE FROM itemCreators WHERE itemID=? AND orderIndex=?';
						Zotero_DB::query($sql2, array($this->id, $orderIndex), $shardID);
						
						if (!$creator) {
							continue;
						}
						
						if ($creator['ref']->hasChanged()) {
							Z_Core::debug("Auto-saving changed creator {$creator['ref']->id}");
							$creator['ref']->save();
						}
						
						
						$placeholders[] = "(?, ?, ?, ?)";
						array_push(
							$sqlValues,
							$this->id,
							$creator['ref']->id,
							$creator['creatorTypeID'],
							$orderIndex
						);
					}
					
					if ($sqlValues) {
						$sql = $sql . implode(',', $placeholders);
						Zotero_DB::query($sql, $sqlValues, $shardID);
					}
					
					// Update memcache
					$cacheRows = array();
					$cs = $this->getCreators();
					foreach ($cs as $orderIndex=>$c) {
						$cacheRows[] = array(
							'creatorID' => $c['ref']->id,
							'creatorTypeID' => $c['creatorTypeID'],
							'orderIndex' => $orderIndex
						);
					}
					Z_Core::$MC->set("itemCreators_" . $this->id, $cacheRows);
				}
				
				// Deleted item
				if ($this->changed['deleted']) {
					$deleted = $this->getDeleted();
					if ($deleted) {
						$sql = "REPLACE INTO deletedItems (itemID) VALUES (?)";
					}
					else {
						$sql = "DELETE FROM deletedItems WHERE itemID=?";
					}
					Zotero_DB::query($sql, $this->id, $shardID);
					Z_Core::$MC->set("itemIsDeleted_" . $this->id, $deleted ? 1 : 0);
				}
				
				
				// In case this was previously a standalone item,
				// delete from any collections it may have been in
				if ($this->changed['source'] && $this->getSource()) {
					$sql = "DELETE FROM collectionItems WHERE itemID=?";
					Zotero_DB::query($sql, $this->id, $shardID);
				}
				
				//
				// Note or attachment note
				//
				if ($this->changed['note']) {
					// Only record sourceItemID in itemNotes for notes
					if ($this->isNote()) {
						$sourceItemID = $this->getSource();
					}
					$sourceItemID = !empty($sourceItemID) ? $sourceItemID : null;
					$noteText = $this->noteText ? $this->noteText : '';
					$title = Zotero_Notes::noteToTitle($this->noteText);
					$hash = $noteText ? md5($noteText) : '';
					$sql = "INSERT INTO itemNotes
							(itemID, sourceItemID, note, title, hash) VALUES
							(?,?,?,?,?) ON DUPLICATE KEY UPDATE
							sourceItemID=?, note=?, title=?, hash=?";
					$bindParams = array(
						$this->id,
						$sourceItemID, $noteText, $title, $hash,
						$sourceItemID, $noteText, $title, $hash
					);
					Zotero_DB::query($sql, $bindParams, $shardID);
					Zotero_Notes::updateNoteCache($this->libraryID, $this->id, $noteText);
					Zotero_Notes::updateHash($this->libraryID, $this->id, $hash);
					
					// TODO: handle changed source?
				}
				
				
				// Attachment
				if ($this->changed['attachmentData']) {
					$sql = "REPLACE INTO itemAttachments
						(itemID, sourceItemID, linkMode, mimeType, charsetID, path, storageModTime, storageHash)
						VALUES (?,?,?,?,?,?,?,?)";
					$parent = $this->getSource();
					if ($parent) {
						$parentItem = Zotero_Items::get($this->libraryID, $parent);
						if (!$parentItem) {
							throw new Exception("Parent item $parent not found");
						}
						if ($parentItem->getSource()) {
							trigger_error("Parent item cannot be a child attachment", E_USER_ERROR);
						}
					}
					
					$linkMode = $this->attachmentLinkMode;
					$charsetID = Zotero_CharacterSets::getID($this->attachmentCharset);
					$path = $this->attachmentPath;
					$storageModTime = $this->attachmentStorageModTime;
					$storageHash = $this->attachmentStorageHash;
					
					$bindParams = array(
						$this->id,
						$parent ? $parent : null,
						$linkMode + 1,
						$this->attachmentMIMEType,
						$charsetID ? $charsetID : null,
						$path ? $path : '',
						$storageModTime ? $storageModTime : null,
						$storageHash ? $storageHash : null
					);
					Zotero_DB::query($sql, $bindParams, $shardID);
				}
				
				//
				// Source item id
				//
				if ($this->changed['source']) {
					$type = Zotero_ItemTypes::getName($this->itemTypeID);
					$Type = ucwords($type);
					
					// Update DB, if not a note or attachment we already changed above
					if (!$this->changed['attachmentData'] && (!$this->changed['note'] || !$this->isNote())) {
						$sql = "UPDATE item" . $Type . "s SET sourceItemID=? WHERE itemID=?";
						$parent = $this->getSource();
						$bindParams = array(
							$parent ? $parent : null,
							$this->id
						);
						Zotero_DB::query($sql, $bindParams, $shardID);
					}
				}
				
				
				if (false && $this->changed['source']) {
					trigger_error("Unimplemented", E_USER_ERROR);
					
					$newItem = Zotero_Items::get($this->libraryID, $sourceItemID);
					// FK check
					if ($newItem) {
						if ($sourceItemID) {
						}
						else {
							trigger_error("Cannot set $type source to invalid item $sourceItemID", E_USER_ERROR);
						}
					}
					
					$oldSourceItemID = $this->getSource();
					
					if ($oldSourceItemID == $sourceItemID) {
						Z_Core::debug("$Type source hasn't changed", 4);
					}
					else {
						$oldItem = Zotero_Items::get($this->libraryID, $oldSourceItemID);
						if ($oldSourceItemID && $oldItem) {
						}
						else {
							//$oldItemNotifierData = null;
							Z_Core::debug("Old source item $oldSourceItemID didn't exist in setSource()", 2);
						}
						
						// If this was an independent item, remove from any collections where it
						// existed previously and add source instead if there is one
						if (!$oldSourceItemID) {
							$sql = "SELECT collectionID FROM collectionItems WHERE itemID=?";
							$changedCollections = Zotero_DB::query($sql, $itemID, $shardID);
							if ($changedCollections) {
								trigger_error("Unimplemented", E_USER_ERROR);
								if ($sourceItemID) {
									$sql = "UPDATE OR REPLACE collectionItems "
										. "SET itemID=? WHERE itemID=?";
									Zotero_DB::query($sql, array($sourceItemID, $this->id), $shardID);
								}
								else {
									$sql = "DELETE FROM collectionItems WHERE itemID=?";
									Zotero_DB::query($sql, $this->id, $shardID);
								}
							}
						}
						
						$sql = "UPDATE item{$Type}s SET sourceItemID=?
								WHERE itemID=?";
						$bindParams = array(
							$sourceItemID ? $sourceItemID : null,
							$itemID
						);
						Zotero_DB::query($sql, $bindParams, $shardID);
						
						//Zotero.Notifier.trigger('modify', 'item', $this->id, notifierData);
						
						// Update the counts of the previous and new sources
						if ($oldItem) {
							/*
							switch ($type) {
								case 'note':
									$oldItem->decrementNoteCount();
									break;
								case 'attachment':
									$oldItem->decrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', oldSourceItemID, oldItemNotifierData);
						}
						
						if ($newItem) {
							/*
							switch ($type) {
								case 'note':
									$newItem->incrementNoteCount();
									break;
								case 'attachment':
									$newItem->incrementAttachmentCount();
									break;
							}
							*/
							//Zotero.Notifier.trigger('modify', 'item', sourceItemID, newItemNotifierData);
						}
					}
				}
				
				// Related items
				if (!empty($this->changed['relatedItems'])) {
					$removed = array();
					$newids = array();
					$currentIDs = $this->relatedItems;
					
					if (!$currentIDs) {
						$currentIDs = array();
					}
					
					if ($this->previousData['relatedItems']) {
						foreach($this->previousData['relatedItems'] as $id) {
							if (!in_array($id, $currentIDs)) {
								$removed[] = $id;
							}
						}
					}
					
					foreach ($currentIDs as $id) {
						if ($this->previousData['relatedItems'] &&
								in_array($id, $this->previousData['relatedItems'])) {
							continue;
						}
						$newids[] = $id;
					}
					
					if ($removed) {
						$sql = "DELETE FROM itemRelated WHERE itemID=?
								AND linkedItemID IN (";
						$q = array_fill(0, sizeOf($removed), '?');
						$sql .= implode(', ', $q) . ")";
						Zotero_DB::query(
							$sql,
							array_merge(array($this->id), $removed),
							$shardID
						);
					}
					
					if ($newids) {
						$sql = "INSERT INTO itemRelated (itemID, linkedItemID)
								VALUES (?,?)";
						$insertStatement = Zotero_DB::getStatement($sql, false, $shardID);
						
						foreach ($newids as $linkedItemID) {
							$insertStatement->execute(array($this->id, $linkedItemID));
						}
					}
					
					Z_Core::$MC->set("itemRelated_" . $this->id, $currentIDs);
				}
			}
			
			Zotero_DB::commit();
		}
		
		catch (Exception $e) {
			Zotero_DB::rollback();
			throw ($e);
		}
		
		if (!$this->id) {
			$this->id = $itemID;
		}
		if (!$this->key) {
			$this->key = $key;
		}
		
		// TODO: invalidate memcache
		Zotero_Items::reload($this->libraryID, $this->id);
		
		if ($isNew) {
			Zotero_Items::cache($this);
		}
		
		// Queue item for addition to search index
		Zotero_Solr::queueItem($this->libraryID, $this->key);
		
		if ($isNew) {
			//Zotero.Notifier.trigger('add', 'item', $this->getID());
			return $this->id;
		}
		
		//Zotero.Notifier.trigger('modify', 'item', $this->getID(), { old: $this->_preChangeArray });
		return true;
	}
	
	
	/*
	 * Returns the number of creators for this item
	 */
	public function numCreators() {
		if ($this->id && !$this->loaded['creators']) {
			$this->loadCreators();
		}
		return sizeOf($this->creators);
	}
	
	
	/**
	 * @param	int
	 * @return	Zotero_Creator
	 */
	public function getCreator($orderIndex) {
		if ($this->id && !$this->loaded['creators']) {
			$this->loadCreators();
		}
		
		return isset($this->creators[$orderIndex])
			? $this->creators[$orderIndex] : false;
	}
	
	
	/**
	 * Gets the creators in this object
	 *
	 * @return	array				Array of Zotero_Creator objects
	 */
	public function getCreators() {
		if ($this->id && !$this->loaded['creators']) {
			$this->loadCreators();
		}
		
		return $this->creators;
	}
	
	
	public function setCreator($orderIndex, Zotero_Creator $creator, $creatorTypeID) {
		if ($this->id && !$this->loaded['creators']) {
			$this->loadCreators();
		}
		
		if (!is_integer($orderIndex)) {
			throw new Exception("orderIndex must be an integer");
		}
		if (!($creator instanceof Zotero_Creator)) {
			throw new Exception("creator must be a Zotero_Creator object");
		}
		if (!is_integer($creatorTypeID)) {
			throw new Exception("creatorTypeID must be an integer");
		}
		if (!Zotero_CreatorTypes::getID($creatorTypeID)) {
			throw new Exception("Invalid creatorTypeID '$creatorTypeID'");
		}
		if ($this->libraryID != $creator->libraryID) {
			throw new Exception("Creator library IDs don't match");
		}
		
		// If creator already exists at this position, cancel
		if (isset($this->creators[$orderIndex])
				&& $this->creators[$orderIndex]['ref']->id == $creator->id
				&& $this->creators[$orderIndex]['creatorTypeID'] == $creatorTypeID
				&& !$creator->hasChanged()) {
			Z_Core::debug("Creator in position $orderIndex hasn't changed", 4);
			return false;
		}
		
		$this->creators[$orderIndex]['ref'] = $creator;
		$this->creators[$orderIndex]['creatorTypeID'] = $creatorTypeID;
		$this->changed['creators'][$orderIndex] = true;
		return true;
	}
	
	
	/*
	* Remove a creator and shift others down
	*/
	public function removeCreator($orderIndex) {
		if ($this->id && !$this->loaded['creators']) {
			$this->loadCreators();
		}
		
		if (!isset($this->creators[$orderIndex])) {
			trigger_error("No creator exists at position $orderIndex", E_USER_ERROR);
		}
		
		$this->creators[$orderIndex] = false;
		array_splice($this->creators, $orderIndex, 1);
		for ($i=$orderIndex, $max=sizeOf($this->creators)+1; $i<$max; $i++) {
			$this->changed['creators'][$i] = true;
		}
		return true;
	}
	
	
	public function isRegularItem() {
		return !($this->isNote() || $this->isAttachment());
	}
	
	
	public function numChildren($includeTrashed=false) {
		return $this->numNotes($includeTrashed) + $this->numAttachments($includeTrashed);
	}

	
	
	//
	//
	// Child item methods
	//
	//
	/**
	* Get the itemID of the source item for a note or file
	**/
	public function getSource() {
		if (isset($this->sourceItem)) {
			if (!$this->sourceItem) {
				return false;
			}
			if (is_int($this->sourceItem)) {
				return $this->sourceItem;
			}
			$sourceItem = Zotero_Items::getByLibraryAndKey($this->libraryID, $this->sourceItem);
			if (!$sourceItem) {
				throw new Exception("Source item $this->libraryID/$this->sourceItem for keyed source doesn't exist", Z_ERROR_ITEM_NOT_FOUND);
			}
			// Replace stored key with id
			$this->sourceItem = $sourceItem->id;
			return $sourceItem->id;
		}
		
		if (!$this->id) {
			return false;
		}
		
		if ($this->isNote()) {
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$Type = 'Attachment';
		}
		else {
			return false;
		}
		
		$sql = "SELECT sourceItemID FROM item{$Type}s WHERE itemID=?";
		$sourceItemID = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		// Temporary sanity check
		if ($sourceItemID && !is_int($sourceItemID)) {
			trigger_error("sourceItemID is not an integer", E_USER_ERROR);
		}
		if (!$sourceItemID) {
			$sourceItemID = false;
		}
		$this->sourceItem = $sourceItemID;
		return $sourceItemID;
	}
	
	
	/**
	 * Get the key of the source item for a note or file
	 * @return	{String}
	 */
	public function getSourceKey() {
		if (isset($this->sourceItem)) {
			if (is_int($this->sourceItem)) {
				$sourceItem = Zotero_Items::get($this->libraryID, $this->sourceItem);
				return $sourceItem->key;
			}
			return $this->sourceItem;
		}
		
		if (!$this->id) {
			return false;
		}
		
		if ($this->isNote()) {
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$Type = 'Attachment';
		}
		else {
			return false;
		}
		
		$sql = "SELECT `key` FROM item{$Type}s A JOIN items B ON (A.sourceItemID=B.itemID) WHERE A.itemID=?";
		$key = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$key) {
			$key = false;
		}
		$this->sourceItem = $key;
		return $key;
	}
	
	
	public function setSource($sourceItemID) {
		if ($this->isNote()) {
			$type = 'note';
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$type = 'attachment';
			$Type = 'Attachment';
		}
		else {
			trigger_error("setSource() can only be called on notes and attachments", E_USER_ERROR);
		}
		
		$this->sourceItem = $sourceItemID;
		$this->changed['source'] = true;
	}
	
	
	public function setSourceKey($sourceItemKey) {
		if ($this->isNote()) {
			$type = 'note';
			$Type = 'Note';
		}
		else if ($this->isAttachment()) {
			$type = 'attachment';
			$Type = 'Attachment';
		}
		else {
			throw new Exception("setSourceKey() can only be called on notes and attachments");
		}
		
		$oldSourceItemID = $this->getSource();
		if ($oldSourceItemID) {
			$sourceItem = Zotero_Items::get($this->libraryID, $oldSourceItemID);
			$oldSourceItemKey = $sourceItem->key;
		}
		else {
			$oldSourceItemKey = null;
		}
		if ($oldSourceItemKey == $sourceItemKey) {
			Z_Core::debug("Source item has not changed in Zotero_Item->setSourceKey()");
			return false;
		}
		
		$this->sourceItem = $sourceItemKey ? $sourceItemKey : null;
		$this->changed['source'] = true;
		
		return true;
	}
	
	
	/**
	 * Returns number of child attachments of item
	 *
	 * @param	{Boolean}	includeTrashed		Include trashed child items in count
	 * @return	{Integer}
	 */
	public function numAttachments($includeTrashed=false) {
		if (!$this->isRegularItem()) {
			trigger_error("numAttachments() can only be called on regular items", E_USER_ERROR);
		}
		
		if (!$this->id) {
			return 0;
		}
		
		$deleted = 0;
		if ($includeTrashed) {
			$sql = "SELECT COUNT(*) FROM itemAttachments JOIN deletedItems USING (itemID)
					WHERE sourceItemID=?";
			$deleted = (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		return $this->numAttachments + $deleted;
	}
	
	
	//
	//
	// Note methods
	//
	//
	/**
	 * Get the first line of the note for display in the items list
	 *
	 * Note: Note titles can also come from Zotero.Items.cacheFields()!
	 *
	 * @return	{String}
	 */
	public function getNoteTitle() {
		if (!$this->isNote() && !$this->isAttachment()) {
			throw ("getNoteTitle() can only be called on notes and attachments");
		}
		
		if ($this->noteTitle !== null) {
			return $this->noteTitle;
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT title FROM itemNotes WHERE itemID=?";
		$title = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->noteTitle = $title ? $title : '';
		return $this->noteTitle;
	}

	
	
	/**
	* Get the text of an item note
	**/
	public function getNote() {
		if (!$this->isNote() && !$this->isAttachment()) {
			throw new Exception("getNote() can only be called on notes and attachments");
		}
		
		if (!$this->id) {
			return '';
		}
		
		// Store access time for later garbage collection
		//$this->noteAccessTime = new Date();
		
		if (!is_null($this->noteText)) {
			return $this->noteText;
		}
		
		$note = Zotero_Notes::getCachedNote($this->libraryID, $this->id);
		if ($note === false) {
			$sql = "SELECT note FROM itemNotes WHERE itemID=?";
			$note = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		$this->noteText = $note ? $note : '';
		
		return $this->noteText;
	}
	
	
	public function getNoteHash() {
		if (!$this->isNote() && !$this->isAttachment()) {
			trigger_error("getNoteHash() can only be called on notes and attachments", E_USER_ERROR);
		}
		
		if (!$this->id) {
			return '';
		}
		
		// Store access time for later garbage collection
		//$this->noteAccessTime = new Date();
		
		return Zotero_Notes::getHash($this->libraryID, $this->id);
	}
	
	
	/**
	* Set an item note
	*
	* Note: This can only be called on notes and attachments
	**/
	public function setNote($text) {
		if (!$this->isNote() && !$this->isAttachment()) {
			trigger_error("setNote() can only be called on notes and attachments", E_USER_ERROR);
		}
		
		$currentHash = $this->getNoteHash();
		$hash = $text ? md5($text) : false;
		if ($currentHash == $hash) {
			Z_Core::debug("Note text hasn't changed in setNote()");
			return;
		}
		
		$this->noteText = $text;
		$this->changed['note'] = true;
	}
	
	
	/**
	 * Returns number of child notes of item
	 *
	 * @param	{Boolean}	includeTrashed		Include trashed child items in count
	 * @return	{Integer}
	 */
	public function numNotes($includeTrashed=false) {
		if ($this->isNote()) {
			throw new Exception("numNotes() cannot be called on items of type 'note'");
		}
		
		if (!$this->id) {
			return 0;
		}
		
		$deleted = 0;
		if ($includeTrashed) {
			$sql = "SELECT COUNT(*) FROM itemNotes WHERE sourceItemID=? AND
					itemID IN (SELECT itemID FROM deletedItems)";
			$deleted = (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		}
		
		return $this->numNotes + $deleted;
	}
	
	
	//
	//
	// Methods dealing with item notes
	//
	//
	/**
	* Returns an array of note itemIDs for this item
	**/
	public function getNotes() {
		if ($this->isNote()) {
			throw new Exception("getNotes() cannot be called on items of type 'note'");
		}
		
		if (!$this->id) {
			return array();
		}
		
		$sql = "SELECT N.itemID FROM itemNotes N NATURAL JOIN items
				WHERE sourceItemID=? ORDER BY title";
		
		/*
		if (Zotero.Prefs.get('sortNotesChronologically')) {
			sql += " ORDER BY dateAdded";
			return Zotero.DB.columnQuery(sql, $this->id);
		}
		*/
		
		$itemIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$itemIDs) {
			return array();
		}
		return $itemIDs;
	}
	
	
	//
	//
	// Attachment methods
	//
	//
	/**
	 * Get the link mode of an attachment
	 *
	 * Possible return values specified as constants in Zotero.Attachments
	 * (e.g. Zotero.Attachments.LINK_MODE_LINKED_FILE)
	 */
	private function getAttachmentLinkMode() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentLinkMode can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['linkMode'] !== null) {
			return $this->attachmentData['linkMode'];
		}
		
		if (!$this->id) {
			return null;
		}
		
		// Return ENUM as 0-index integer
		$sql = "SELECT linkMode - 1 FROM itemAttachments WHERE itemID=?";
		// DEBUG: why is this returned as a float without the cast?
		$linkMode = (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->attachmentData['linkMode'] = $linkMode;
		return $linkMode;
	}
	
	
	/**
	 * Get the MIME type of an attachment (e.g. 'text/plain')
	 */
	private function getAttachmentMIMEType() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentMIMEType can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['mimeType'] !== null) {
			return $this->attachmentData['mimeType'];
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT mimeType FROM itemAttachments WHERE itemID=?";
		$mimeType = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$mimeType) {
			$mimeType = '';
		}
		$this->attachmentData['mimeType'] = $mimeType;
		return $mimeType;
	}
	
	
	/**
	 * Get the character set of an attachment
	 *
	 * @return	string					Character set name
	 */
	private function getAttachmentCharset() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentCharset can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['charset'] !== null) {
			return $this->attachmentData['charset'];
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT charsetID FROM itemAttachments WHERE itemID=?";
		$charset = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if ($charset) {
			$charset = Zotero_CharacterSets::getName($charset);
		}
		else {
			$charset = '';
		}
		
		$this->attachmentData['charset'] = $charset;
		return $charset;
	}
	
	
	private function getAttachmentPath() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentPath can only be retrieved for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['path'] !== null) {
			return $this->attachmentData['path'];
		}
		
		if (!$this->id) {
			return '';
		}
		
		$sql = "SELECT path FROM itemAttachments WHERE itemID=?";
		$path = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$path) {
			$path = '';
		}
		$this->attachmentData['path'] = $path;
		return $path;
	}
	
	
	private function getAttachmentStorageModTime() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentStorageModTime can only be retrieved
				for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['storageModTime'] !== null) {
			return $this->attachmentData['storageModTime'];
		}
		
		if (!$this->id) {
			return null;
		}
		
		$sql = "SELECT storageModTime FROM itemAttachments WHERE itemID=?";
		$val = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->attachmentData['storageModTime'] = $val;
		return $val;
	}
	
	
	private function getAttachmentStorageHash() {
		if (!$this->isAttachment()) {
			trigger_error("attachmentStorageHash can only be retrieved
				for attachment items", E_USER_ERROR);
		}
		
		if ($this->attachmentData['storageHash'] !== null) {
			return $this->attachmentData['storageHash'];
		}
		
		if (!$this->id) {
			return null;
		}
		
		$sql = "SELECT storageHash FROM itemAttachments WHERE itemID=?";
		$val = Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		$this->attachmentData['storageHash'] = $val;
		return $val;
	}
	
	
	private function setAttachmentField($field, $val) {
		Z_Core::debug("Setting attachment field $field to '$val'");
		switch ($field) {
			case 'mimeType':
				$field = 'mimeType';
				$fieldCap = 'MIMEType';
				break;
			
			case 'linkMode':
			case 'charset':
			case 'storageModTime':
			case 'storageHash':
			case 'path':
				$fieldCap = ucwords($field);
				break;
				
			default:
				trigger_error("Invalid attachment field $field", E_USER_ERROR);
		}
		
		if (!$this->isAttachment()) {
			trigger_error("attachment$fieldCap can only be set for attachment items", E_USER_ERROR);
		}
		
		if ($field == 'linkMode') {
			switch ($val) {
				// TODO: get these constants from somewhere
				// TODO: validate field for this link mode
				case 0:
				case 1:
				case 2:
				case 3:
					break;
					
				default:
					trigger_error("Invalid attachment link mode '$val' in "
						. "Zotero_Item::attachmentLinkMode setter", E_USER_ERROR);
			}
		}
		
		if (!is_int($val) && !$val) {
			$val = '';
		}
		
		$fieldName = 'attachment' . $fieldCap;
		
		if ($val === $this->$fieldName) {
			return;
		}
		
		$this->changed['attachmentData'][$field] = true;
		$this->attachmentData[$field] = $val;
	}
	
	
	/**
	* Returns an array of attachment itemIDs that have this item as a source,
	* or FALSE if none
	**/
	public function getAttachments() {
		if ($this->isAttachment()) {
			throw new Exception("getAttachments() cannot be called on attachment items");
		}
		
		if (!$this->id) {
			return false;
		}
		
		$sql = "SELECT itemID FROM items NATURAL JOIN itemAttachments WHERE sourceItemID=?";
		
		// TODO: reimplement sorting by title using values from MongoDB?
		
		/*
		if (Zotero.Prefs.get('sortAttachmentsChronologically')) {
			sql +=  " ORDER BY dateAdded";
			return Zotero.DB.columnQuery(sql, this.id);
		}
		*/
		
		$itemIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$itemIDs) {
			return array();
		}
		return $itemIDs;
	}
	
	
	
	//
	// Methods dealing with tags
	//
	// save() is not required for tag functions
	//
	public function numTags() {
		if (!$this->id) {
			return 0;
		}
		$sql = "SELECT COUNT(*) FROM itemTags WHERE itemID=?";
		return (int) Zotero_DB::valueQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
	}
	
	
	/**
	 * Returns all tags assigned to an item
	 *
	 * @return	array			Array of Zotero.Tag objects
	 */
	public function getTags($asIDs=false) {
		if (!$this->id) {
			return array();
		}
		
		$sql = "SELECT tagID FROM tags JOIN itemTags USING (tagID)
				WHERE itemID=? ORDER BY name";
		$tagIDs = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		if (!$tagIDs) {
			return array();
		}
		
		if ($asIDs) {
			return $tagIDs;
		}
		
		$tagObjs = array();
		foreach ($tagIDs as $tagID) {
			$tag = Zotero_Tags::get($this->libraryID, $tagID, true);
			$tagObjs[] = $tag;
		}
		return $tagObjs;
	}
	
	
	/**
	 * $tags is an array of objects with properties 'tag' and 'type'
	 */
	public function setTags($newTags) {
		if (!$this->id) {
			throw new Exception('itemID not set');
		}
		
		$numTags = $this->numTags();
		
		if (!$newTags && !$numTags) {
			return false;
		}
		
		Zotero_DB::beginTransaction();
		
		$existingTags = $this->getTags();
		
		$toAdd = array();
		$toRemove = array();
		
		// Get new tags not in existing
		for ($i=0, $len=sizeOf($newTags); $i<$len; $i++) {
			if (!isset($newTags[$i]->type)) {
				$newTags[$i]->type = 0;
			}
			
			$name = $newTags[$i]->tag; // 'tag', not 'name', since that's what JSON uses
			$type = $newTags[$i]->type;
			
			foreach ($existingTags as $tag) {
				if ($tag->name == $name && $tag->type == $type) {
					continue 2;
				}
			}
			
			$toAdd[] = $newTags[$i];
		}
		
		// Get existing tags not in new
		for ($i=0, $len=sizeOf($existingTags); $i<$len; $i++) {
			$name = $existingTags[$i]->name;
			$type = $existingTags[$i]->type;
			
			foreach ($newTags as $tag) {
				if ($tag->tag == $name && $tag->type == $type) {
					continue 2;
				}
			}
			
			$toRemove[] = $existingTags[$i];
		}
		
		foreach ($toAdd as $tag) {
			$name = $tag->tag;
			$type = $tag->type;
			
			$tagID = Zotero_Tags::getID($this->libraryID, $name, $type);
			if (!$tagID) {
				$tag = new Zotero_Tag;
				$tag->libraryID = $this->libraryID;
				$tag->name = $name;
				$tag->type = $type;
				$tagID = $tag->save();
			}
			
			$tag = Zotero_Tags::get($this->libraryID, $tagID);
			$tag->addItem($this->id);
			$tag->save();
		}
		
		foreach ($toRemove as $tag) {
			$tag->removeItem($this->id);
			$tag->save();
		}
		
		Zotero_DB::commit();
		
		return $toAdd || $toRemove;
	}
	
	
	
	public function toAtom($content='none', $apiVersion=null) {
		return Zotero_Items::convertItemToAtom($this, $content, $apiVersion);
	}
	
	
	public function toJSON($asArray=false, $prettyPrint=false) {
		if ($this->id || $this->key) {
			if (!$this->loaded['primaryData']) {
				$this->loadPrimaryData(true);
			}
			if (!$this->loaded['itemData']) {
				$this->loadItemData();
			}
		}
		
		$regularItem = $this->isRegularItem();
		
		$arr = array();
		$arr['itemType'] = Zotero_ItemTypes::getName($this->itemTypeID);
		
		// For regular items, show title and creators first
		if ($regularItem) {
			// Get 'title' or the equivalent base-mapped field
			$titleFieldID = Zotero_ItemFields::getBaseIDFromTypeAndField($this->itemTypeID, 'title');
			$titleFieldName = Zotero_ItemFields::getName($titleFieldID);
			$arr[$titleFieldName] = $this->itemData[$titleFieldID];
			
			// Creators
			$arr['creators'] = array();
			$creators = $this->getCreators();
			foreach ($creators as $creator) {
				$c = array();
				$c['creatorType'] = Zotero_CreatorTypes::getName($creator['creatorTypeID']);
				
				// Single-field mode
				if ($creator['ref']->fieldMode == 1) {
					$c['name'] = $creator['ref']->lastName;
				}
				// Two-field mode
				else {
					$c['firstName'] = $creator['ref']->firstName;
					$c['lastName'] = $creator['ref']->lastName;
				}
				$arr['creators'][] = $c;
			}
		}
		else {
			$titleFieldID = false;
		}
			
		// Item metadata
		foreach ($this->itemData as $field=>$value) {
			if ($field == $titleFieldID) {
				continue;
			}
			
			$arr[Zotero_ItemFields::getName($field)] =
				$this->itemData[$field] ? $this->itemData[$field] : '';
		}
		
		// Embedded note for notes and attachments
		if (!$regularItem) {
			$arr['note'] = $this->getNote();
		}
		
		// Tags
		$arr['tags'] = array();
		$tags = $this->getTags();
		if ($tags) {
			foreach ($tags as $tag) {
				$t = array(
					'tag' => $tag->name
				);
				if ($tag->type != 0) {
					$t['type'] = $tag->type;
				}
				$arr['tags'][] = $t;
			}
		}
		
		if ($asArray) {
			return $arr;
		}
		
		$mask = JSON_HEX_TAG|JSON_HEX_AMP;
		if ($prettyPrint) {
			$json = Zotero_Utilities::json_encode_pretty($arr, $mask);
		}
		else {
			$json = json_encode($arr, $mask);
		}
		// Until JSON_UNESCAPED_SLASHES is available
		$json = str_replace('\\/', '/', $json);
		return $json;
	}
	
	
	public function toSolrDocument() {
		$doc = new SolrInputDocument();
		
		$uri = Zotero_Solr::getItemURI($this->libraryID, $this->key);
		$doc->addField("uri", $uri);
		
		// Primary fields
		foreach (Zotero_Items::$primaryFields as $field) {
			switch ($field) {
				case 'itemID':
				case 'numAttachments':
				case 'numNotes':
					continue (2);
				
				case 'itemTypeID':
					$xmlField = 'itemType';
					$xmlValue = Zotero_ItemTypes::getName($this->$field);
					break;
				
				case 'dateAdded':
				case 'dateModified':
				case 'serverDateModified':
					$xmlField = $field;
					$xmlValue = Zotero_Date::sqlToISO8601($this->$field);
					break;
				
				default:
					$xmlField = $field;
					$xmlValue = $this->$field;
			}
			
			$doc->addField($xmlField, $xmlValue);
		}
		
		// Title for sorting
		$title = $this->getDisplayTitle(true);
		$title = $title ? $title : '';
		// Strip HTML from note titles
		if ($this->isNote()) {
			// Clean and strip HTML, giving us an HTML-encoded plaintext string
			$title = strip_tags($GLOBALS['HTMLPurifier']->purify($title));
			// Unencode plaintext string
			$title = html_entity_decode($title);
		}
		// Strip some characters
		$sortTitle = preg_replace("/^[\[\'\"]*(.*)[\]\'\"]*$/", "$1", $title);
		if ($sortTitle) {
			$doc->addField('titleSort', $sortTitle);
		}
		
		// Item data
		$fieldIDs = $this->getUsedFields();
		foreach ($fieldIDs as $fieldID) {
			$val = $this->getField($fieldID);
			if ($val == '') {
				continue;
			}
			
			$fieldName = Zotero_ItemFields::getName($fieldID);
			
			switch ($fieldName) {
				// As is
				case 'title':
					$val = $title;
					break;
				
				// Date fields
				case 'date':
					// Add user part as text
					$doc->addField($fieldName . "_t", Zotero_Date::multipartToStr($val));
					
					// Add as proper date, if there is one
					$sqlDate = Zotero_Date::multipartToSQL($val);
					if (!$sqlDate || $sqlDate == '0000-00-00') {
						continue 2;
					}
					$fieldName .= "_tdt";
					$val = Zotero_Date::sqlToISO8601($sqlDate);
					break;
				
				case 'accessDate':
					if (!Zotero_Date::isSQLDateTime($val)) {
						continue 2;
					}
					$fieldName .= "_tdt";
					$val = Zotero_Date::sqlToISO8601($val);
					break;
				
				default:
					$fieldName .= "_t";
			}
			
			$doc->addField($fieldName, $val);
		}
		
		// Deleted item flag
		if ($this->getDeleted()) {
			$doc->addField('deleted', true);
		}
		
		if ($this->isNote() || $this->isAttachment()) {
			$sourceItemID = $this->getSource();
			if ($sourceItemID) {
				$sourceItem = Zotero_Items::get($this->libraryID, $sourceItemID);
				if (!$sourceItem) {
					throw new Exception("Source item $sourceItemID not found");
				}
				$doc->addField('sourceItem', $sourceItem->key);
			}
		}
		
		// Group modification info
		$createdByUserID = null;
		$lastModifiedByUserID = null;
		switch (Zotero_Libraries::getType($this->libraryID)) {
			case 'group':
				$createdByUserID = $this->createdByUserID;
				$lastModifiedByUserID = $this->lastModifiedByUserID;
				break;
		}
		if ($createdByUserID) {
			$doc->addField('createdByUserID', $createdByUserID);
		}
		if ($lastModifiedByUserID) {
			$doc->addField('lastModifiedByUserID', $lastModifiedByUserID);
		}
		
		// Note
		if ($this->isNote()) {
			$doc->addField('note', $this->getNote());
		}
		
		if ($this->isAttachment()) {
			$doc->addField('linkMode', $this->attachmentLinkMode);
			$doc->addField('mimeType', $this->attachmentMIMEType);
			if ($this->attachmentCharset) {
				$doc->addField('charset', $this->attachmentCharset);
			}
			
			// TODO: get from a constant
			if ($this->attachmentLinkMode != 3) {
				$doc->addField('path', $this->attachmentPath);
			}
			
			$note = $this->getNote();
			if ($note) {
				$doc->addField('note', $note);
			}
		}
		
		// Creators
		$creators = $this->getCreators();
		if ($creators) {
			foreach ($creators as $index => $creator) {
				$c = $creator['ref'];
				
				$doc->addField('creatorKey', $c->key);
				if ($c->fieldMode == 0) {
					$doc->addField('creatorFirstName', $c->firstName);
				}
				$doc->addField('creatorLastName', $c->lastName);
				$doc->addField('creatorType', Zotero_CreatorTypes::getName($creator['creatorTypeID']));
				$doc->addField('creatorIndex', $index);
			}
		}
		
		// Tags
		$tags = $this->getTags();
		if ($tags) {
			foreach ($tags as $tag) {
				$doc->addField('tagKey', $tag->key);
				$doc->addField('tag', $tag->name);
				$doc->addField('tagType', $tag->type);
			}
		}
		
		// Related items
		/*$related = $this->relatedItems;
		if ($related) {
			$related = Zotero_Items::get($this->libraryID, $related);
			$keys = array();
			foreach ($related as $item) {
				$doc->addField('relatedItem', $item->key);
			}
		}*/
		
		return $doc;
	}
	
	
	public function toCSLItem() {
		return Zotero_Cite::retrieveItem($this);
	}
	
	
	//
	//
	// Private methods
	//
	//
	private function loadItemData() {
		Z_Core::debug("Loading item data for item $this->id");
		
		// TODO: remove?
		if ($this->loaded['itemData']) {
			trigger_error("Item data for item $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->id) {
			trigger_error('Item ID not set before attempting to load data', E_USER_ERROR);
		}
		
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$sql = "SELECT fieldID, itemDataValueHash AS hash FROM itemData WHERE itemID=?";
		$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
		$fields = Zotero_DB::queryFromStatement($stmt, $this->id);
		
		$itemTypeFields = Zotero_ItemFields::getItemTypeFields($this->itemTypeID);
		
		if ($fields) {
			foreach($fields as $field) {
				$value = Zotero_Items::getDataValue($field['hash']);
				if ($value === false) {
					throw new Exception("Item data value for hash '{$field['hash']}' not found");
				}
				$this->setField($field['fieldID'], $value, true, true);
			}
		}
		
		// Mark nonexistent fields as loaded
		if ($itemTypeFields) {
			foreach($itemTypeFields as $fieldID) {
				if (is_null($this->itemData[$fieldID])) {
					$this->itemData[$fieldID] = false;
				}
			}
		}
		
		$this->loaded['itemData'] = true;
	}
	
	
	private function loadCreators() {
		if (!$this->id) {
			trigger_error('Item ID not set for item before attempting to load creators', E_USER_ERROR);
		}
		
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$cacheKey = "itemCreators_" . $this->id;
		$creators = Z_Core::$MC->get($cacheKey);
		$creators = false;
		if ($creators === false) {
			$sql = "SELECT creatorID, creatorTypeID, orderIndex FROM itemCreators
					WHERE itemID=? ORDER BY orderIndex";
			$stmt = Zotero_DB::getStatement($sql, true, Zotero_Shards::getByLibraryID($this->libraryID));
			$creators = Zotero_DB::queryFromStatement($stmt, $this->id);
			
			Z_Core::$MC->set($cacheKey, $creators ? $creators : array());
		}
		
		$this->creators = array();
		$this->loaded['creators'] = true;
		
		if (!$creators) {
			return;
		}
		
		foreach ($creators as $creator) {
			$creatorObj = Zotero_Creators::get($this->libraryID, $creator['creatorID']);
			if (!$creatorObj) {
				Z_Core::$MC->delete($cacheKey);
				throw new Exception("Creator {$creator['creatorID']} not found");
			}
			$this->creators[$creator['orderIndex']] = array(
				'creatorTypeID' => $creator['creatorTypeID'],
				'ref' => $creatorObj
			);
		}
	}
	
	
	private function loadRelatedItems() {
		if (!$this->id) {
			return;
		}
		
		Z_Core::debug("Loading related items for item $this->id");
		
		if ($this->loaded['relatedItems']) {
			trigger_error("Related items for item $this->id already loaded", E_USER_ERROR);
		}
		
		if (!$this->loaded['primaryData']) {
			$this->loadPrimaryData(true);
		}
		
		// TODO: use a prepared statement
		if (!is_numeric($this->id)) {
			trigger_error("Invalid itemID '$this->id'", E_USER_ERROR);
		}
		
		$cacheKey = "itemRelated_" . $this->id;
		$ids = Z_Core::$MC->get($cacheKey);
		$ids = false;
		if ($ids !== false) {
			$this->relatedItems = $ids;
			$this->loaded['relatedItems'] = true;
			return;
		}
		
		$sql = "SELECT linkedItemID FROM itemRelated WHERE itemID=?";
		$ids = Zotero_DB::columnQuery($sql, $this->id, Zotero_Shards::getByLibraryID($this->libraryID));
		
		$this->relatedItems = $ids ? $ids : array();
		$this->loaded['relatedItems'] = true;
		
		Z_Core::$MC->set($cacheKey, $this->relatedItems);
	}
	
	
	private function getRelatedItems() {
		if (!$this->loaded['relatedItems']) {
			$this->loadRelatedItems();
		}
		return $this->relatedItems;
	}
	
	
	private function setRelatedItems($itemIDs) {
		if (!$this->loaded['relatedItems']) {
			$this->loadRelatedItems();
		}
		
		if (!is_array($itemIDs))  {
			trigger_error('$itemIDs must be an array', E_USER_ERROR);
		}
		
		$currentIDs = $this->relatedItems;
		if (!$currentIDs) {
			$currentIDs = array();
		}
		$oldIDs = array(); // children being kept
		$newIDs = array(); // new children
		
		if (!$itemIDs) {
			if (!$currentIDs) {
				Z_Core::debug("No related items added", 4);
				return false;
			}
		}
		else {
			foreach ($itemIDs as $itemID) {
				if ($itemID == $this->id) {
					Z_Core::debug("Can't relate item to itself in Zotero.Item.setRelatedItems()", 2);
					continue;
				}
				
				if (in_array($itemID, $currentIDs)) {
					Z_Core::debug("Item {$this->id} is already related to item $itemID");
					$oldIDs[] = $itemID;
					continue;
				}
				
				// TODO: check if related on other side (like client)?
				
				$newIDs[] = $itemID;
			}
		}
		
		// Mark as changed if new or removed ids
		if ($newIDs || sizeOf($oldIDs) != sizeOf($currentIDs)) {
			$this->storePreviousData('relatedItems');
			$this->changed['relatedItems'] = true;
		}
		else {
			Z_Core::debug('Related items not changed', 4);
			return false;
		}
		
		$this->relatedItems = array_merge($oldIDs, $newIDs);
		return true;
	}
	
	
	private function getETag() {
		if (!$this->loaded['primaryData']) {
			$this->loadPrimaryData();
		}
		
		return md5($this->serverDateModified . '.' . $this->serverDateModifiedMS);
	}
	
	
	private function storePreviousData($field) {
		$this->previousData[$field] = $this->$field;
	}
	
	
	private function generateKey() {
		return Zotero_ID::getKey();
	}
}
?>
