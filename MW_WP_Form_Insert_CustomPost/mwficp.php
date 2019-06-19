<?php
/*
Plugin Name: MW WP Form Insert CustomPost
Plugin URI:
Description: MW_WP_Formの機能拡張プラグインです。フォームから送信された情報のうち、任意のものをカスタム投稿に追加します。
Version: 1.0
Author: Keisuke Yamazaki
Author URI:
License: GPL2
*/
/*  Copyright 2019 Keisuke Yamazaki (email : 4leafclover1214@gmail.com)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
     published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
?>
<?php
if(!class_exists('MwWpFormInsertCustomPost')){
  class MwWpFormInsertCustomPost{
    //設定そのものの識別番号
    private $setting_numbers = array();
    public function __construct(){
      //mw_formにフックするための処理
      $this->settingNumbersGet();
      foreach($this->setting_numbers as $number){
        $hook_identifier = $number->option_value;
        //mw_formで用意されたアクションフック。
        add_action( 'mwform_before_send_admin_mail_mw-wp-form-' . $hook_identifier, array( $this, 'mwFormWhenSending' ) );
      }
      //管理画面にメニュー追加
      add_action( 'admin_menu', array( $this, 'addMenu' ) );
    }
    /*******************/
    /*****共通の処理*****/
    /******************/
    protected function settingNumbersGet(){
      global $wpdb;
      $sql = "
        SELECT option_name,option_value
        FROM $wpdb->options
        WHERE option_name LIKE 'MWFICP-setting-num%'
  		";
  		$this->setting_numbers = $wpdb->get_results($sql);
    }
    /*******************/
    /*動作するときの処理*/
    /******************/
    public function mwFormWhenSending($Mail_admin){
      //引数（フォームから受け取る値）のキャスト
      $cast_first = (array)$Mail_admin;
    	$cast_second_origin = $cast_first["\0*\0Mail_Parser"];
    	$cast_second = (array)$cast_second_origin;
      //デバッグ用 var_dump($cast_second);
    	$cast_third_origin = $cast_second["\0*\0Data"];
    	$cast_third = (array)$cast_third_origin;
    	$data = $cast_third["\0*\0variables"];
      //フォームの識別子を元にこのプラグインでの設定をDBから取得する
      $identifier = $data['mw-wp-form-form-id'];
      global $wpdb;
  		$num_sql = $wpdb->prepare( "
  			SELECT option_name
  			FROM $wpdb->options
  			WHERE option_value = %s AND
        option_name LIKE 'MWFICP-setting-num-%'
  			",$identifier
      );
    	if(!empty($num_sql)){
        $setting_num = $wpdb->get_results($num_sql);
        $setting_num = str_replace('-num-', 's', $setting_num[0]->option_name);
      }
      if(!empty($setting_num)){
        $settings_array = get_option($setting_num);
      }
      $type = $settings_array['postname'];
    	$status = $settings_array['status'];
    	$insert_post = array(
    		'post_content' => ' ',
    		'post_title' => ' ',
    		'post_status' => $status,
    		'post_type' => $type //カスタム投稿名
    	);
    	$insert_id = wp_insert_post( $insert_post, false ); //エラーの場合0を返す
      if($insert_id !== 0){
        foreach($settings_array as $key => $settings){
          //タグ名で登録されているものがあった場合
          if(strpos($key,'relation-tagname') !== false){
            //タグ名=mw_formの設定タグなので、引数の整形dataの配列からタグ名指定で取ってくる
            $insert_pure_data = $data[$settings];
            //値の検査。チェックボックスの場合は配列になる
            //デバッグ用 var_dump($insert_pure_data);
            if(is_array($insert_pure_data)){
              $insert_data_array = $insert_pure_data['data'];
              $insert_data_separator = $insert_pure_data['separator'];
              if(is_array($insert_data_array)){
                $insert_data = '';
                foreach($insert_data_array as $insert_data_single){
                  $insert_data = $insert_data . $insert_data_single . $insert_data_separator;
                }
                $insert_data = substr($insert_data, 0, -1);
              }else{
                $insert_data = $insert_data_array;
              }
            }else{
              $insert_data = $insert_pure_data;
            }
            //relation-tagnamexxの数値と同じrelation-postnamexxを持ってくる
            $tag_num = str_replace('relation-tagname', '', $key);
            $insert_custompost = $settings_array['relation-postname' . $tag_num];
            if($insert_custompost == 'post_title'){
              wp_update_post(
                array(
                  'ID' => $insert_id,
                  'post_title' => $insert_data,
                )
              );
            }elseif($insert_custompost == 'post_content'){
              wp_update_post(
                array(
                  'ID' => $insert_id,
                  'post_content' => $insert_data,
                )
              );
            }elseif($insert_custompost != ''){
              add_post_meta( $insert_id, $insert_custompost, $insert_data );
            }
          }
        }
    	}else{
    		echo 'エラーが発生しました。お手数お掛けしますが、お問い合わせください。';
    	}
    }
    /*******************/
    /***設定画面の処理***/
    /******************/
    public function addMenu(){
      add_submenu_page('edit.php?post_type=mw-wp-form', '（カスタム）投稿への挿入設定', '投稿への挿入設定', 'manage_options', 'mw-wp-form-insert-custom-post', array($this, 'pluginPage'));
    }
    public function pluginPage(){
      if (!current_user_can('manage_options')){
        wp_die( __('アクセス権限がありません') );
      }
      $message = '';
      if(isset($_POST['MWFICP-check']) && $_POST['MWFICP-check'] === 'OK'){
        $message = $this->optionUpdate();
      }
      if(isset($_POST['MWFICP-delete'])){
        $message = $this->settingDelete();
      }
      $this->settingNumbersGet();
      $numbers = array();
      foreach($this->setting_numbers as $setting_number){
        $numbers[] = str_replace('MWFICP-setting-num-', '', $setting_number->option_name);
      }
      asort($numbers);
      $this->RendersettingDom($numbers,$message);
    }
    protected function RendersettingDom($numbers,$message){
      if($message != ''){
        echo '
          <div id="message" class="message">' . $message . '</div>
        ';
      }
      echo '
      <h2>投稿への挿入設定</h2>
      <p>※画像やファイルをフォームから送信する場合は、MW WP Formの「問い合わせデータをデータベースに保存」を有効にしてください。</p>
      <div id="settings">
      ';
      if(empty($numbers)){
        $numbers[0] = 1;
      }
      foreach($numbers as $num){
        $area_margin = '';
        $show_identifier = '';
        $show_postname = '';
        $show_status = 'publish';
        $relation_clear = array();
        $tag_count = 0;
        $post_count = 0;
        if($num >= 2){
          $area_margin = 'style="margin:20px 0 0;"';
        }
        if(get_option('MWFICP-settings' . $num)){
          $settings = get_option('MWFICP-settings' . $num);
          $show_identifier = $settings['identifier'];
          $show_postname = $settings['postname'];
          $show_status = $settings['status'];
          foreach($settings as $key => $relation){
            if(strpos($key,'relation-tagname') !== false){
              $relation_clear[$tag_count]['tag'] = $relation;
              $tag_count++;
            }
            if(strpos($key,'relation-postname') !== false){
              $relation_clear[$post_count]['postname'] = $relation;
              $post_count++;
            }
          }
        }
        if($tag_count == 0 && $post_count == 0){
          $relation_clear[0]['tag'] = '';
          $relation_clear[0]['postname'] = '';
        }
        echo '
          <div ' . $area_margin . ' class="post-area" id="setting' . $num . '">
            <form method="post" action="">
              <input type="hidden" name="MWFICP-delete" value="' . $num . '">
              <button type="submit" id="delete' . $num . '" class="delete-button" onclick=" return deleteAttention();">×</button>
            </form>
            <form method="post" action="">
              <input type="hidden" name="MWFICP-check" value="OK">
              <input type="hidden" id="setting-num' . $num . '" name="MWFICP-number" value="' . $num . '">
              <h4>MW WP Formのフォーム識別子を入力してください。</h4>
              <p>[mwform_formkey key="xx"]のxxの数値です。</p>
              <input type="number" name="MWFICP-identifier" value="' . $show_identifier . '">
              <h4 class="border-top">挿入したいカスタム投稿名を入力してください。</h4>
              <p>通常の投稿の場合はpost、固定ページの場合はpageと入力してください。</p>
              <input type="text" name="MWFICP-postname" value="' . $show_postname . '">
              <h4 class="border-top">どのステータスで投稿を挿入するか選択してください。</h4><br>
              <select name="MWFICP-status">
                <option value="publish"'; if($show_status == 'publish'){ echo 'selected'; } echo '>公開</option>
                <option value="draft"'; if($show_status == 'draft'){ echo 'selected'; } echo '>下書き</option>
                <option value="pending"'; if($show_status == 'pending'){ echo 'selected'; } echo '>承認待ち</option>
                <option value="private"'; if($show_status == 'private'){ echo 'selected'; } echo '>非公開</option>
              </select>
              <h4 class="border-top">MW WP Formのフォームタグのname属性と、挿入したいフィールドを結び付けてください。</h4>
              <p>選択できるフィールドは、投稿タイトル、投稿本文、カスタムフィールドのいずれかです。<br>
              投稿タイトルの場合はpost_title、投稿本文の場合はpost_content、カスタムフィールドの場合はカスタムフィールド名を入力してください。<br>
              入力フィールドが重複した場合は、上のものが優先されます。</p>
              <button type="button" id="addrelation' . $num . '" class="button" onclick="addRelationField(this.id);">フィールドを追加</button>
              <ul id="setting' . $num . '-relation-field-wrap">';
              if(!isset($relation_num)){
                $relation_num = 1;
              }
              foreach ($relation_clear as $relation_show) {
                //最初だけidを01にするため
                if ($relation_show === reset($relation_clear)) {
                  echo '
                  <li id="setting' . $num . '-relation-field-item01">
                    <input id="setting' . $num . '-relation-field-tagname01" type="text" name="MWFICP-relation-tagname01" placeholder="name" value="' . $relation_show['tag'] . '">を<input id="setting' . $num . '-relation-field-postname01"  type="text" name="MWFICP-relation-postname01" placeholder="カスタムフィールド名"value="' . $relation_show['postname'] . '">に挿入
                  </li>';
                }else{
                  echo '
                  <li id="setting' . $num . '-relation-field-item0' . $relation_num . '">
                    <input id="setting' . $num . '-relation-field-tagname0' . $relation_num . '" type="text" name="MWFICP-relation-tagname0' . $relation_num . '" placeholder="name" value="' . $relation_show['tag'] . '">を<input id="setting' . $num . '-relation-field-postname0' . $relation_num . '"  type="text" name="MWFICP-relation-postname0' . $relation_num . '" placeholder="カスタムフィールド名"value="' . $relation_show['postname'] . '">に挿入
                  </li>';
                }
                $relation_num++;
              }
              echo '
              </ul>
              <input type="submit" class="button button-primary button-large" value="設定完了">
            </form>
          </div>';
      }
      $relation_count = 2;
      if($relation_num >= 2){
        $relation_count = $relation_num + 1;
      }
      $setting_count = 2;
      if(!empty($numbers)){
        $setting_count = max($numbers) + 1;
      }
      echo '
      </div>
      <p><button type="button" class="button" onclick="addSettingField();">設定項目を追加</button></p>
      <script>
        //Native Javascript Only
        //結びつけのフィールドを追加する。
        var count = ' . $relation_count . ';
        function addRelationField(e){
          var setting_num = e.replace("addrelation","");
          var parent = document.getElementById("setting" + setting_num + "-relation-field-wrap");
          var first_field = document.getElementById("setting" + setting_num + "-relation-field-item01");
          var clone_field = first_field.cloneNode(true);
          clone_field.id = "setting" + setting_num + "-relation-field-item0" + count;
          var clone_children = clone_field.children;
          var clone_tagname = clone_children[0];
          clone_tagname.id = "setting" + setting_num + "-relation-field-tagname0" + count;
          clone_tagname.name = "MWFICP-relation-tagname0" + count;
          clone_tagname.value = "";
          var clone_postname = clone_children[1];
          clone_postname.id = "setting" + setting_num + "-relation-field-postname0" + count;
          clone_postname.name = "MWFICP-relation-postname0" + count;
          clone_postname.value = "";
          parent.appendChild(clone_field);
          count ++;
        }
        //設定自体を追加する
        var setting_count = ' . $setting_count . ';
        function addSettingField(){
          var setting_field = document.getElementById("setting' . ($setting_count - 1) . '");
          var clone_field = setting_field.cloneNode(true);
          clone_field.id = "setting" + setting_count;
          clone_field.style.margin = "20px 0 0 0";
          var children = clone_field.childNodes;
          for(var i=0; i < children.length; i++){
            if(children[i].method){
              var form_tag = children[i];
            }
          }
          for(var i=0; i < form_tag.length; i++){
            var form_tag_name = form_tag[i].name;
            if(form_tag_name == "MWFICP-identifier"){
              form_tag[i].value = "";
            }
            if(form_tag_name == "MWFICP-postname"){
              form_tag[i].value = "";
            }
            if(form_tag_name == "MWFICP-number"){
              form_tag[i].id = "setting-num" + setting_count;
              form_tag[i].value = setting_count;
            }
          }
          var relation_field_button = form_tag.children;
          for(var i=0; i < relation_field_button.length; i++){
            var relation_field_button_id = relation_field_button[i].id;
            if(relation_field_button_id.match(/addrelation/)){
              relation_field_button[i].id = "addrelation" + setting_count;
            }
            if(relation_field_button_id.match(/-relation-field-wrap/)){
              relation_field_button[i].id = "setting" + setting_count + "-relation-field-wrap";
              var relation_field_items = relation_field_button[i].children;
              //li要素の1個目だけクローンを作る
              var relation_field_items_clone = relation_field_items[0].cloneNode(true);
              relation_field_items_clone.id = "setting" + setting_count + "-relation-field-item01";
              var relation_field_items_clone_children = relation_field_items_clone.children;
              for(var k=0; k < relation_field_items_clone_children.length; k++){
                var relation_field_items_clone_child_id = relation_field_items_clone_children[k].id;
                if(relation_field_items_clone_child_id.match(/-relation-field-tagname/)){
                  relation_field_items_clone_children[k].id = "setting" + setting_count + "-relation-field-tagname01";
                  relation_field_items_clone_children[k].value = "";
                }
                if(relation_field_items_clone_child_id.match(/-relation-field-postname/)){
                  relation_field_items_clone_children[k].id = "setting" + setting_count + "-relation-field-postname01";
                  relation_field_items_clone_children[k].value = "";
                }
              }
              //子要素を消す
              var relation_field_item_flame_clone = relation_field_button[i].cloneNode(false);
              relation_field_button[i].parentNode.replaceChild(relation_field_item_flame_clone , relation_field_button[i]);
              //li要素の1個目のクローンを入れる
              relation_field_button[i].appendChild(relation_field_items_clone);
            }
          }
          var parent = document.getElementById("settings");
          parent.appendChild(clone_field);
          setting_count ++;
        }
        function deleteAttention(){
          var check = confirm("この設定を削除しますか？");
          if(check){
            return true;
          }else{
            return false;
          }
        }
      </script>
      <style>
        .post-area{ position:relative; width:95%; padding:12px; background:#FFF; border:1px solid #e5e5e5; box-shadow:0 1px 1px rgba(0,0,0,.04); }
        .border-top{ padding:20px 0 0; border-top:1px solid #e5e5e5; margin:20px 0 0; }
        .delete-button{ position:absolute; top:10px; right:10px; border:none; background:transparent; font-size:24px; cursor:pointer; }
        .message{ background:#FFF; border-left:4px solid #A00; box-shadow:0 1px 1px 0 rgba(0,0,0,.1); margin:20px 20px 20px 0; padding:6px 12px; }
      </style>
      ';
    }
    protected function optionUpdate(){ //関数実行前に必ずPOST内容の有無と正しいPOSTかのチェックをすること
      $option_num = $_POST['MWFICP-number'];
      $identifier = $_POST['MWFICP-identifier'];
      $postname = $_POST['MWFICP-postname'];
      $status = $_POST['MWFICP-status'];
      $relation_postnames = array();
      $relation_tagnames = array();
      //MW WP Formの{フォームタグの name}と挿入したいフィールドを結び付け設定
      //空白の場合は登録しない、重複の場合は上のものが優先で登録する
      //DB登録時点で付番しなおすためid名が連番になっていなくても関係ない
      foreach ($_POST as $key => $value) {
        if(strpos($key,'MWFICP-relation-postname') !== false){
          $postnum = explode('MWFICP-relation-postname', $key);
          if($value != '' && $postnum && !in_array($value, $relation_postnames)){ //配列の重複チェック、重複の場合は配列に追加しない
            $num = $postnum[1];
            $relation_postnames[$num] = $value;
          }
        }
        if(strpos($key,'MWFICP-relation-tagname') !== false){
          $tagnum = explode('MWFICP-relation-tagname', $key);
          if($value != '' && $tagnum){ //配列の重複チェック、タグは重複ok。textフィールドに入力したものをpost_titleとカスタムフィールド両方に入れたいなどあるはず
            $num = $tagnum[1];
            $relation_tagnames[$num] = $value;
          }
        }
      }
      //両配列のうちキー項目が同じもののみだけ取り出す
      $relation_postnames_clean = array_intersect_key($relation_postnames, $relation_tagnames);
      $relation_tagnames_clean = array_intersect_key($relation_tagnames, $relation_postnames);
      //基本設定を配列に入れる
      $insert_settings = array(
        'identifier' => $identifier,
        'postname' => $postname,
        'status' => $status
      );
      //結びつけの設定を配列に入れる
      $i = 1;
      foreach ($relation_postnames_clean as $key => $value) {
        $insert_settings['relation-postname' . $i] = $value;
        $insert_settings['relation-tagname' . $i] = $relation_tagnames_clean[$key];
        $i++;
      }
      $update_status = false;
      if($option_num != '' && $identifier != ''){
        //MWFICP-setting-num-xxが設定ひとかたまりの識別番号
        update_option('MWFICP-setting-num-' . $option_num, $identifier);
        $update_status = update_option('MWFICP-settings' . $option_num, $insert_settings);
      }
      if($update_status !== false){
        return '設定を更新しました。';
      }else{
        return '設定の更新に失敗しました。';
      }

    }
    protected function settingDelete(){ //関数実行前に必ずPOST内容の有無と正しいPOSTかのチェックをすること
      $delete_num = $_POST['MWFICP-delete'];
      if(get_option('MWFICP-setting-num-' . $delete_num) && get_option('MWFICP-settings' . $delete_num)){
        $delete_status01 = delete_option('MWFICP-setting-num-' . $delete_num);
        $delete_status02 = delete_option('MWFICP-settings' . $delete_num);
        if($delete_status01 !== false && $delete_status02 !== false){
          return '設定を削除しました。';
        }else{
          return '設定に失敗しました。';
        }
      }else{
        return '削除しようとした設定がありませんでした。';
      }
    }
  }
  $mw_wp_form_insert_custom_post = new MwWpFormInsertCustomPost();
}
?>
