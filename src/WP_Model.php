<?php

/**
 * WP_Model
 *
 * A simple class for creating active
 * record, eloquent-esque models of WordPress Posts.
 *
 * @todo
 * - Document Funtions
 * - Support data types: Array, Integer
 * @author     AnthonyBudd <anthonybudd94@gmail.com>
 */
Abstract Class WP_Model implements JsonSerializable
{
	protected $attributes = [];
	protected $tax_data = [];
	protected $data = [];
	protected $booted = FALSE;
	public $new = TRUE;
	public $dirty = FALSE;
	public $ID = FALSE;
	public $prefix = '';
	public $title;
	public $content;
	public $_post;


	/**
	 * Create a new instace with data
	 * @param Array $insert Asoc array of data to start the instace with
	 */
	public function __construct(Array $insert = [])
	{  
		if(count($insert) !== 0){
			foreach($insert as $attribute => $value){
				if(in_array($attribute, array_merge($this->attributes, ['title', 'content']))){
					$this->set($attribute, $value);
				}
			}

			if(!empty($insert['title'])){
				$this->title = $insert['title'];
			}
			if(!empty($insert['content'])){
				$this->content = $insert['content'];
			}
		}

		$this->boot();
	}

	/**
	 * Create a new instace with data and save
	 * @param Array $insert Asoc array of data to start the instace with
	 */
	public static function insert(Array $insert = []){
		return Self::newInstance($insert)->save();
	}

	/**
	 * Loads data into the model
	 */
	protected function boot()
	{
		$this->triggerEvent('booting');

		if(!empty($this->ID)){
			$this->new = FALSE;
			$this->_post = get_post($this->ID);
			$this->title = $this->_post->post_title;
			$this->content = $this->_post->post_content;

			foreach($this->attributes as $attribute){
				$this->set($attribute, $this->getMeta($attribute));
			}
		}

		if(!empty($this->taxonomies)){
			foreach($this->taxonomies as $taxonomy){
				$this->tax_data[$taxonomy] = get_the_terms($this->ID, $taxonomy);
				if($this->tax_data[$taxonomy] === FALSE){
					$this->tax_data[$taxonomy] = [];
				}
			}
		}

		$this->booted = TRUE;
		$this->triggerEvent('booted');
	}

	/**
	 * Fire event if the event method exists
	 * @param  String $event event name
	 * @return Boolean
	 */
	protected function triggerEvent($event)
	{
		if(method_exists($this, $event)){
			$this->$event($this);
		}
	}

	/**
	 * Register the post type using the name
	 * propery as the post type name
	 * @param  Array  $args register_post_type() args
	 * @return Boolean
	 */
	public static function register($args = [])
	{
		$check = Self::newInstance();
		foreach($check->attributes as $attribute){	
			if(in_array($attribute, ['new', 'dirty', '_id', 'ID', 'prefix', 'title', 'content', '_post'])){
				throw new Exception("The attribute name: {$attribute}, is reserved for WP_Model");
			}
		}

		$postTypeName = Self::getName();

		$defualts = [
			'public' => TRUE,
			'label' => ucfirst($postTypeName)
		];

		register_post_type($postTypeName, array_merge($defualts, $args));

		Self::addHooks();

		return TRUE;
	}

	// -----------------------------------------------------
	// HOOKS
	// -----------------------------------------------------
	/**
	 * Add operational hooks
	 */
	public static function addHooks()
	{
		add_action(('save_post'), [get_called_class(), 'onSave'], 9999999999);
	}
 
 	/**
	 * Remove operational hooks
	 */
	public static function removeHooks()
	{
		remove_action(('save_post'), [get_called_class(), 'onSave'], 9999999999);
	}

	/**
	 * Hook
	 * Saves the post's meta
	 * @param INT $ID
	 */
	public static function onSave($ID)
	{
		if(get_post_status($ID) == 'publish' &&
			Self::exists($ID)){ // If post is the right post type
			$post = Self::find($ID);
			$post->save();
		}
	}

	// -----------------------------------------------------
	// UTILITY METHODS
	// -----------------------------------------------------
	/**
	 * Get the name propery of the inherited class.
	 * @return String
	 */
	public static function getName()
	{
		$class = get_called_class();
		return ( new ReflectionClass($class) )->getProperty('name')->getValue( (new $class) );
	}

	/**
	 * Returns a new instance of the class
	 * @return Object
	 */
	public static function newInstance($insert = [])
	{
		$class = get_called_class();
		return new $class($insert);
	}

	/**
	 * Return array representaion of the model for serialization 
	 * @return Array
	 */
	public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function getMeta($key){
		return get_post_meta($this->ID, ($this->prefix.$key), TRUE);
	}

	public function setMeta($key, $value){
		update_post_meta($this->ID, ($this->prefix.$key), $value);
	}

	public function deleteMeta($key){
		delete_post_meta($this->ID, ($this->prefix.$key));
	}


    // -----------------------------------------------------
	// GETTERS & SETTERS
	// -----------------------------------------------------
	/**
	 * Get property of model
	 * @param  property name $attribute [description]
	 * @return requested property or NULL
	 */
	public function get($attribute, $default = NULL)
	{
		if(isset($this->data[$attribute])){
			return $this->data[$attribute];
		}

		return $default;
	}

	/**
	 * Set property of model
	 * @param String $attribute
	 * @param String $value
	 */
	public function set($attribute, $value)
	{
		$this->data[$attribute] = $value;
	}

	public function setTaxonomy($attribute, $value)
	{
		if(isset($this->taxonomies[$attribute])){
			if(is_int($value)){
				$term = get_term_by('id', $attribute, $value);
			}else{
				$term = get_term_by('name', $attribute, $value);
			}
		}
	}

	public function getTaxonomy($attribute, $default = NULL)
	{
		if(isset($this->taxonomies) && isset($this->tax_data[$attribute])){
			return $this->tax_data[$attribute];
		}

		return $default;
	}

	public function hasVirtualProperty($attribute){
		return method_exists($this, ('_get'. ucfirst($attribute)));
	}

	public function getVirtualProperty(){
		return call_user_func([$this, ('_get'. ucfirst($attribute))]);  
	}

	// -----------------------------------------------------
	// HELPER METHODS
	// -----------------------------------------------------
	/**
	 * Check if the post exists by Post ID
	 * @param  String|Integer  $id   Post ID
	 * @param  Boolean $type Require post to be the same post type
	 * @return Boolean
	 */
	public static function exists($id, $type = TRUE)
	{	
		if($type){
			if(
				(get_post_status($id) !== FALSE) &&
				(get_post_type($id) == Self::getName())){
				return TRUE;
			}
		}else{
			return (get_post_status($id) !== FALSE);
		}

		return FALSE;
	}

	/**
	 * Get the original post of the model
	 * @return WP_Post
	 */
	public function post()
	{
		return $this->_post;
	}

	/**
	 * Get model's featured image
	 * @param  string $default
	 * @return string (URL)
	 */
	public function featuredImage($default = '')
	{
		$featuredImage = get_the_post_thumbnail_url($this->ID);
		return ($featuredImage !== FALSE)? $featuredImage : $default;
	}

	/**
	 * Return array representaion of the model
	 * @return Array
	 */
	public function toArray()
	{
		$model = [];

		foreach($this->attributes as $key => $attribute){
			if(!empty($this->protected) && !in_array($attribute, $this->protected)){
				// Do not add to $model
			}else{
				$model[$attribute] = $this->get($attribute);
			}
		}

		if(!empty($this->serialize)){
			foreach($this->serialize as $key => $attribute){
				if(!empty($this->protected) && !in_array($attribute, $this->protected)){
					// Do not add to $model
				}else{
					$model[$attribute] = $this->$attribute;
				}
			}
		}

		$model['ID'] = $this->ID;
		$model['title'] = $this->title;
		$model['content'] = $this->content;

		return $model;
	}

	/**
	 * Get the model for a single page or in the loop
	 * @return Model | NULL
	 */
	public function single(){
		return Self::find(get_the_ID());
	}

	// -----------------------------------------------------
	// MAGIC METHODS
	// -----------------------------------------------------
	public function __set($attribute, $value)
	{
		if($this->booted){
			$this->dirty = true;
		}

		if(in_array($attribute, $this->attributes)){
			$this->set($attribute, $value);
		}else if(isset($this->taxonomies) && in_array($attribute, $this->taxonomies)){
			$this->setTaxonomy($attribute, $value);
		}
	}

	public function __get($attribute)
	{
		if(in_array($attribute, $this->attributes)){
			return $this->get($attribute);
		}else if(isset($this->taxonomies) && in_array($attribute, $this->taxonomies)){
			return $this->getTaxonomy($attribute);
		}else if($this->hasVirtualProperty($attribute)){
			return $this->getVirtualProperty($attribute);
		}else if($attribute === 'post_title'){
			return $this->title;
		}else if($attribute === 'post_content'){
			return $this->content;
		}
	}

	//-----------------------------------------------------
	// FINDERS
	// ----------------------------------------------------
	/**
	 * Find model by ID
	 * @param  INT $ID model post ID
	 * @return Null | Object
	 */
	public static function find($ID)
	{
		if(Self::exists($ID)){
			$class = Self::newInstance();
			$class->ID = $ID;
			$class->boot();
			return $class;
		}

		return NULL;
	}

	/**
	 * Get model by ID without booting the model
	 * @param  Model ID
	 * @return Null | Object
	 */
	public static function findBypassBoot($ID)
	{
		if(Self::exists($ID)){
			$class = Self::newInstance();
			$class->ID = $ID;
			return $class;
		}

		return NULL;
	}

	/**
	 * Find, if the model does not exist throw
	 * @throws  \Exception
	 * @param  Integer $id post ID
	 * @return Self
	 */
	public static function findOrFail($ID)
	{
		if(!Self::exists($ID)){
			throw new Exception("Post {$ID} not found");
		}

		return Self::find($ID);
	}

	/**
	 * Returns all of a post type
	 * @param  String $limit max posts
	 * @return Array
	 */
	public static function all($limit = '999999999')
	{
		$return = [];
		$args = [
			'post_type' 	 => Self::getName(),
			'posts_per_page' => $limit,
			'order'          => 'DESC',
			'orderby'        => 'id',
		];

		foreach((new WP_Query($args))->get_posts() as $key => $post){
			$return[] = Self::find($post->ID);
		}

		return $return;
	}

	/**
	 * 
	 * @param  String  $metaKey [description]
	 * @param  Array   $posts   Array of WP_Posts, IDs or models (optional)
	 * @return Array
	 */
	public static function asList($value = 'title', $models = FALSE)
	{
		if(!is_array($models)){
			$self = get_called_class();
			$models = $self::all();
		}

		$return = [];
		foreach($models as $model){
			if(is_int($model) || $model instanceof WP_Post){
				$model = Self::find($model->ID);
			}
			
			$return[$model->ID] = $model->$value;
		}

		return $return;
	}

	/**
	 * [finder description]
	 * @param  [type] $finder [description]
	 * @return [type]         [description]
	 */
	public static function finder($finder)
	{
		$return = [];
		$finderMethod = $finder.'Finder';
		$self = get_called_class();

		if(!in_array($finderMethod, array_column(( new ReflectionClass(get_called_class()) )->getMethods(), 'name'))){
			throw new Exception("Finder method {$finderMethod} not found in {$self}");
		}

		
		$args = $self::$finderMethod();
		if(!is_array($args)){
			throw new Exception("Finder method must return an array");
		}

		$args['post_type'] = Self::getName();
		foreach((new WP_Query($args))->get_posts() as $key => $post){
			$return[] = Self::find($post->ID);
		}

		$postFinderMethod = $finder.'PostFinder';
		if(in_array($postFinderMethod, array_column(( new ReflectionClass(get_called_class()) )->getMethods(), 'name'))){
			return $self::$postFinderMethod($return);
		}

		return $return;
	}

	public static function where($key, $value = FALSE)
	{
		if(is_array($key)){
			$params = [
				'post_type' => Self::getName(),
				'meta_query' => [],
				'tax_query' => [],
			];

			foreach($key as $meta){
				if(!empty($meta['taxonomy'])){
					$params['tax_query'][] = [
						'taxonomy' => $meta['taxonomy'],
                		'field'    => isset($meta['field'])? $meta['field'] : 'slug',
                		'terms'    => $meta['terms'],
                		'operator'    => isset($meta['operator'])? $meta['operator'] : 'IN',
					];
				}else{
					$params['meta_query'][] = [
						'key'       => $meta['key'],
						'value'     => $meta['value'],
						'compare'   => isset($meta['compare'])? $meta['compare'] : '=',
						'type'      => isset($meta['type'])? $meta['type'] : 'CHAR'
					];
				}
			}

			$query = new WP_Query($params);
		}else{
			$query = new WP_Query([
				'post_type' => Self::getName(),
				'meta_query'        => [
					[
						'key'       => $key,
						'value'     => $value,
						'compare'   => '=',
					],
				]
			]);
		}

		$arr = [];
		foreach($query->get_posts() as $key => $post){
			$arr[] = Self::find($post->ID); 
		}

		return $arr;
	}

	public static function in($ids = [])
	{
		$results = [];
		if(!is_array($ids)){
			$ids = func_get_args();
		}

		foreach($ids as $key => $id){
			if(Self::exists($id)){
				$results[] = Self::find($id); 
			}
		}

		return $results;
	}

	// -----------------------------------------------------
	// SAVE
	// -----------------------------------------------------
	/**
	 * Save the model and all of it's asociated data
	 * @return Object $this
	 */
	public function save()
	{
		$this->triggerEvent('saving');

		$overwrite = [
			'post_type' => $this->name
		];

		Self::removeHooks();

		if(is_integer($this->ID)){
			$defualts = [
				'ID'           => $this->ID,
				'post_title'   => $this->title,
				'post_content' => ($this->content !== NULL)? $this->content :  ' ',
			];

			wp_update_post(array_merge($defualts, $overwrite));
		}else{
			$this->triggerEvent('inserting');
			$defualts = [
				'post_status'  => 'publish',
				'post_title'   => $this->title,
				'post_content' => ($this->content !== NULL)? $this->content :  ' ',
			];

			$this->ID = wp_insert_post(array_merge($defualts, $overwrite));
			$this->_post = get_post($this->ID);
			$this->triggerEvent('inserted');
		}

		Self::addHooks();

		foreach($this->attributes as $attribute){
			$this->setMeta($attribute, $this->get($attribute, ''));
		}	

		$this->setMeta('_id', $this->ID);
		$this->triggerEvent('saved');
		$this->dirty = FALSE;
		$this->new = FALSE;
		return $this;
	}

	// -----------------------------------------------------
	// DELETE
	// -----------------------------------------------------
	public function delete(){
		$this->triggerEvent('deleting');
		wp_trash_post($this->ID);
		$this->triggerEvent('deleted');
	}

	public function hardDelete(){
		$this->triggerEvent('hardDeleting');

		$defualts = [
			'ID'           => $this->ID,
			'post_title'   => '',
			'post_content' => '',
		];

		wp_update_post(array_merge($defualts, $args, $overwrite));

		foreach($this->attributes as $attribute){
			$this->deleteMeta($attribute);
			$this->set($attribute, NULL);
		}

		$this->setMeta('_id', $this->ID);
		$this->setMeta('_hardDeleted', '1');
		wp_delete_post($this->ID, TRUE);
		$this->triggerEvent('hardDeleted');
	}

	public static function restore($ID){
		wp_untrash_post($ID);
		return Self::find($ID);
	}

	//-----------------------------------------------------
	// PATCHING 
	//-----------------------------------------------------
	public static function patchable($method = FALSE)
	{
		if(isset($_REQUEST['_model']) &&$_REQUEST['_model'] === Self::getName()){

			if(isset($_REQUEST['_id'])){
				$model = Self::find($_REQUEST['_id']);
			}else{
				$model = Self::newInstance();
				$model->save();
			}

			$model->patch($method);
		}
	}

	public function patch($method = FALSE)
	{
		$this->triggerEvent('patching');

		foreach(array_merge($this->attributes, ['title', 'content']) as $attribute){
			switch($method) {
				case 'set_nulls':
					update_post_meta($this->ID, $attribute, @$_REQUEST[$attribute]);
					break;
				
				default:
					if(isset($_REQUEST[$attribute])){
						update_post_meta($this->ID, $attribute, @$_REQUEST[$attribute]);
					}
					break;
			}
		}

		$this->triggerEvent('patched');
	}
}

?>