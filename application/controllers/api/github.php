<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * @author      OA Wu <comdan66@gmail.com>
 * @copyright   Copyright (c) 2015 OA Wu Design
 */

class Github extends Api_controller {

  public function __construct () {
    parent::__construct ();

    header ('Content-type: text/html');
    if (ENVIRONMENT == 'production')
      header ('Access-Control-Allow-Origin: http://comdan66.github.io');
    else
      header ('Access-Control-Allow-Origin: http://dev.comdan66.github.io');
  }

  private function _weather_bound ($town) {
    return $town && $town->bound ? array ('bound' => array (
                    'northeast' => array (
                      'lat' => $town->bound->northeast_latitude,
                      'lng' => $town->bound->northeast_longitude,
                      ),
                    'southwest' => array (
                      'lat' => $town->bound->southwest_latitude,
                      'lng' => $town->bound->southwest_longitude,
                      )
                  )) : array ();
  }
  private function _weather_view ($town) {
    return $town && $town->view ? array ('view' => array (
                    'lat' => $town->view->latitude,
                    'lng' => $town->view->longitude,
                    'heading' => $town->view->heading,
                    'pitch' => $town->view->pitch,
                    'zoom' => $town->view->zoom,
                      )) : array ();
  }
  private function _weather_format ($town) {
    return $town && ($weather = $town->update_weather ()) ? array (
        'weather' => $weather
      ) : array ();
  }
  private function _content_format ($town) {
    return $town && ($weather = $town->update_weather ()) ? array ('content' => $this->set_method ('weather')->load_content (array (
              'town' => $town,
              'weather' => $weather
            ), true)) : array ();
  }
  private function _town_format ($town) {
    return $town ? array (
        'id' => $town->id,
        'category' => $town->category->name,
        'name' => $town->name,
        'lat' => $town->latitude,
        'lng' => $town->longitude,
      ) : array ();
  }
  private function _town_temperatures ($town) {
    $weathers = TownWeather::find_by_sql ('select id, temperature, HOUR(created_at) AS hour from (SELECT * FROM town_weathers WHERE town_weathers.town_id = ' . $town->id . ' ORDER BY id DESC) AS town_weathers GROUP BY HOUR(created_at) ORDER BY hour DESC LIMIT 0, 6;');
    rsort ($weathers);
    return array ('weathers' => array_map (function ($weather) {
          return array (
              'id' => $weather->id,
              'temperature' => $weather->temperature,
              'hour' => $weather->hour . '時',
            );
        }, $weathers));
  }

  public function get_more_town () {
    $id = trim ($this->input_post ('id'));

    if (!($id && ($town = Town::find_by_id ($id, array ('select' => 'id, town_category_id')))))
      return $this->output_json (array ('status' => false));

    $towns = Town::find ('all', array ('include' => array ('view'), 'limit' => 12, 'order' => 'RAND()', 'conditions' => array ('town_category_id = ? AND id != ?', $town->town_category_id, $town->id)));
    

    if (count ($towns) < 12)
      $towns = array_merge ($towns, Town::find ('all', array ('include' => array ('view'), 'limit' => 12 - count ($towns), 'order' => 'RAND()', 'conditions' => count ($towns) ? array ('id NOT IN (?) AND id != ?', column_array ($towns, 'id'), $town->id) : array ('id != ?', $town->id))));
    
    $towns = array_map (function ($town) {
      return array (
          'id' => $town->id,
          'src' => $town->view ? $town->view->pic->url ('200x200c') : $town->pic->url ('200x200c'),
          'name' => $town->name
        );
    }, $towns);

    return $this->output_json (array ('status' => true, 'towns' => $towns));
  }
  public function get_town () {
    $key = trim ($this->input_post ('key'));

    if (!$key)
      return $this->output_json (array ('status' => false));

    $conditions = is_numeric ($key) ? array ('id = ?', $key) : array ('name LIKE CONCAT("%", ? ,"%")', $key);
    $town = Town::find ('one', array ('conditions' => $conditions));

    if (!($town && $town->update_weather ()))
      return $this->output_json (array ('status' => false));

    if (!($content = $this->_content_format ($town)))
      return $this->output_json (array ('status' => false));
      
    if (!($weather = $this->_weather_format ($town)))
      return $this->output_json (array ('status' => false));
    
    $town = array_filter (array_merge (array (
        'id' => $town->id,
        'lat' => $town->latitude,
        'lng' => $town->longitude,
        'name' => $town->name,
        'category' => $town->category->name,
      ), $content, $weather, $this->_weather_bound ($town), $this->_weather_view ($town), $this->_town_temperatures ($town))); 
    return $this->output_json (array ('status' => true, 'town' => $town));
  }
  public function get_weather_content_by_postal_code () {
    $postal_code = trim ($this->input_post ('postal_code'));

    if (!$postal_code)
      return $this->output_json (array ('status' => false));

    if (!($town = Town::find ('one', array ('conditions' => array ('postal_code = ?', $postal_code)))))
      return $this->output_json (array ('status' => false));
   
    if ($content = $this->_content_format ($town))
      return $this->output_json (array ('status' => true, 'weather' => $content['content']));
    else
      return $this->output_json (array ('status' => false));
  }

  public function get_weather_by_name () {
    $name = trim ($this->input_post ('name'));

    if (!$name)
      return $this->output_json (array ('status' => false));

    $list = array ();
    $list['臺'] = '台';
    $list['北市'] = '台北市';
    $list['北北基'] = '台北';
    $list['花東'] = '花蓮';
    $name = strtr ($name, $list);

    if (!($names = explode (' ', $name)))
      return $this->output_json (array ('status' => false));

    $towns = array ();
    $cates = array ();
    foreach ($names as $name) {
      if ($town = Town::find ('one', array ('conditions' => array ('name LIKE CONCAT("%", ? ,"%")', $name))))
        array_push ($towns, $town);

      if ($cate = TownCategory::find ('one', array ('select' => 'id', 'conditions' => array ('name LIKE CONCAT("%", ? ,"%")', $name))))
        array_push ($cates, $cate);
    }

    if (!$cates && !$towns)
      return $this->output_json (array ('status' => false));

    if ($towns && !$cates && ($town = $towns[0]) && ($content = $this->_content_format ($town)))
      return $this->output_json (array ('status' => true, 'weather' => array_merge ($this->_town_format ($town), $content)));

    if (!$towns && $cates && ($cate = $cates[0]) && ($content = $this->_content_format ($cate->town)))
      return $this->output_json (array ('status' => true, 'weather' => array_merge ($this->_town_format ($town), $content)));

    if ($towns && $cates) {
      $temps = array ();
      foreach ($cates as $cate)
        foreach ($towns as $town)
          if ($town->town_category_id == $cate->id)
            array_push ($temps, $town);
  
      if ($temps && ($town = $temps[0]) && ($content = $this->_content_format ($town)))
        return $this->output_json (array ('status' => true, 'weather' => array_merge ($this->_town_format ($town), $content)));
      else if ($content = $this->_content_format ($towns[0]))
        return $this->output_json (array ('status' => true, 'weather' => array_merge ($this->_town_format ($town), $content)));
      else
        return $this->output_json (array ('status' => false));
    }
    return $this->output_json (array ('status' => false));
  }

  public function get_towns () {
    $limit = 10;
    $towns = array_map (function ($t) {
      return array (
          'id' => $t->id,
          'name' => $t->name
        );
    }, Town::find ('all', array ('select' => 'id, name', 'limit' => $limit, 'order' => 'RAND()')));
    
    return $this->output_json (array ('status' => true, 'towns' => $towns));
  }
  public function get_weathers () {
    $north_east = $this->input_post ('NorthEast');
    $south_west = $this->input_post ('SouthWest');
    $townId = $this->input_post ('townId');
    $zoom = $this->input_post ('zoom');

    if (!($north_east && $south_west && isset ($north_east['latitude']) && isset ($south_west['latitude']) && isset ($north_east['longitude']) && isset ($south_west['longitude'])))
      return $this->output_json (array ('status' => true, 'weathers' => array ()));

    $that = $this;
    $weathers = array_filter (array_map (function ($town) use ($that) {
      $content = $that->_content_format ($town);
      return $content ? array_merge ($that->_town_format ($town), $content) : array ();
    }, Town::find ('all', array ('include' => array ('category'), 'conditions' => array ('id != ? AND zoom <= ? AND (latitude BETWEEN ? AND ?) AND (longitude BETWEEN ? AND ?)', $townId, $zoom, $south_west['latitude'], $north_east['latitude'], $south_west['longitude'], $north_east['longitude'])))));

    return $this->output_json (array ('status' => true, 'weathers' => $weathers));
  }
}
