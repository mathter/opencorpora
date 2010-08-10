<?php
require_once('lib_xml.php');
require_once('lib_books.php');

// GENERAL
function get_dict_stats() {
    $out = array();
    $r = sql_fetch_array(sql_query("SELECT COUNT(*) AS cnt_gt FROM `gram_types`"));
    $out['cnt_gt'] = $r['cnt_gt'];
    $r = sql_fetch_array(sql_query("SELECT COUNT(*) AS cnt_g FROM `gram`"));
    $out['cnt_g'] = $r['cnt_g'];
    $r = sql_fetch_array(sql_query("SELECT COUNT(*) AS cnt_l FROM `dict_lemmata`"));
    $out['cnt_l'] = $r['cnt_l'];
    $r = sql_fetch_array(sql_query("SELECT COUNT(*) AS cnt_f FROM `form2lemma`"));
    $out['cnt_f'] = $r['cnt_f'];
    $r = sql_fetch_array(sql_query("SELECT COUNT(*) AS cnt_r FROM `dict_revisions` WHERE f2l_check=0"));
    $out['cnt_r'] = $r['cnt_r'];
    return $out;
}
function get_dict_search_results($post) {
    $out = array();
    if (isset($post['search_lemma'])) {
        $q = mysql_real_escape_string($post['search_lemma']);
        $res = sql_query("SELECT lemma_id FROM `dict_lemmata` WHERE `lemma_text`='$q'");
        $count = sql_num_rows($res);
        $out['lemma']['count'] = $count;
        if ($count == 0)
            return $out;
        while ($r = sql_fetch_array($res)) {
            $out['lemma']['found'][] = array('id' => $r['lemma_id'], 'text' => $q);
        }
    }
    elseif (isset($post['search_form'])) {
        $q = mysql_real_escape_string($post['search_form']);
        $res = sql_query("SELECT DISTINCT dl.lemma_id, dl.lemma_text FROM `form2lemma` fl LEFT JOIN `dict_lemmata` dl ON (fl.lemma_id=dl.lemma_id) WHERE fl.`form_text`='$q'");
        $count = sql_num_rows($res);
        $out['form']['count'] = $count;
        if ($count == 0)
            return $out;
        while ($r = sql_fetch_array($res)) {
            $out['form']['found'][] = array('id' => $r['lemma_id'], 'text' => $r['lemma_text']);
        }
    }
    return $out;
}
function generate_tf_rev($token) {
    $out = '<tf_rev text="'.htmlspecialchars($token).'">';
    if (preg_match('/[А-Яа-яЁё\-]/u', $token) && $token != '-') {
        $res = sql_query("SELECT lemma_id, lemma_text, grammems FROM form2lemma WHERE form_text='$token'");
        if (sql_num_rows($res) > 0) {
            while($r = sql_fetch_array($res)) {
                $out .= '<var><lemma id="'.$r['lemma_id'].'" text="'.$r['lemma_text'].'">'.$r['grammems'].'</lemma></var>';
            }
        } else {
            $out .= '<var><lemma id="0" text="'.htmlspecialchars(lc($token)).'"><grm val="UnknownPOS"/></lemma></var>';
        }
    } elseif (preg_match('/^[\,\.\:\;\-\(\)\'\"\[\]\?\!\/]+$/', $token)) {
        $out .= '<var><lemma id="0" text="'.htmlspecialchars($token).'"><grm val="PM"/></lemma></var>';
    } else {
        $out .= '<var><lemma id="0" text="'.htmlspecialchars($token).'"><grm val="UnknownPOS"/></lemma></var>';
    }
    $out .= '</tf_rev>';
    return $out;
}
function dict_get_select_gramtype() {
    $res = sql_query("SELECT `type_id`, `type_name` FROM `gram_types` ORDER by `type_name`");
    $out = '';
    while($r = sql_fetch_array($res)) {
        $out .= '<option value="'.$r['type_id'].'">'.$r['type_name'].'</option>';
    }
    return $out;
}
function parse_dict_rev($text) {
    // output has the following structure:
    // lemma => array (text => lemma_text, grm => array (grm1, grm2, ...)),
    // forms => array (
    //     [0] => array (text => form_text, grm => array (grm1, grm2, ...)),
    //     [1] => ...
    // )
    $arr = xml2ary($text);
    $arr = $arr['dict_rev']['_c'];
    $parsed = array();
    $parsed['lemma']['text'] = $arr['lemma']['_a']['text'];
    foreach($arr['form'] as $k=>$farr) {
        $parsed['forms'][$k]['text'] = $farr['_a']['text'];
        $t = array();
        foreach ($farr['_c']['grm'] as $j=>$garr) {
            $t[] = $garr['_a']['val'];
        }
        $parsed['forms'][$k]['grm'] = $t;
    }
    return $parsed;
}
function form_exists($f) {
    $f = lc($f);
    if (!preg_match('/[А-Яа-я\-\']/u', $f)) {
        return -1;
    }
    return sql_num_rows(sql_query("SELECT lemma_id FROM form2lemma WHERE form_text='".mysql_real_escape_string($f)."' LIMIT 1"));
}

// DICTIONARY EDITOR
function dict_page_lemma_edit($id) {
    $out = '';
    if (isset($_GET['saved']))
        $out .= '<p class="p_info">Изменения сохранены.</p>';
    $r = sql_fetch_array(sql_query("SELECT l.`lemma_text`, d.`rev_id`, d.`rev_text` FROM `dict_lemmata` l LEFT JOIN `dict_revisions` d ON (l.lemma_id = d.lemma_id) WHERE l.`lemma_id`=$id ORDER BY d.rev_id DESC LIMIT 1"));
    $out .= '<p><a href="?act=lemmata">&lt;&lt;&nbsp;к поиску</a></p>';
    $arr = parse_dict_rev($r['rev_text']);
    $out .= '<form action="?act=save" method="post"><b>Лемма</b>:<br/><input type="hidden" name="lemma_id" value="'.$id.'"/><input name="lemma_text" readonly="readonly" value="'.htmlspecialchars($arr['lemma']['text']).'"/> (<a href="dict_history.php?lemma_id='.$id.'">история</a>)<br/><b>Формы (оставление левого поля пустым удаляет форму):</b><br/><table cellpadding="3">';
    foreach($arr['forms'] as $farr) {
        $out .= "<tr><td><input name='form_text[]' value='".htmlspecialchars($farr['text'])."'/><td><input name='form_gram[]' size='40' value='".htmlspecialchars(implode(', ', $farr['grm']))."'/>";
        $out .= '</tr>';
    }
    $out .= '<tr><td>&nbsp;<td><a href="#" onClick="dict_add_form(this); return false">Добавить ешё одну форму</a></tr>';
    $out .= '</table><br/><input type="submit" value="Сохранить"/>&nbsp;&nbsp;<input type="reset" value="Сбросить"/></form>';
    //$out .= '<b>Plain xml:</b><br/><textarea class="small" disabled cols="60" rows="10">'.htmlspecialchars($r['rev_text']).'</textarea>';
    return $out;
}
function dict_save($array) {
    //print_r($array);
    $ltext = $array['form_text'];
    $lgram = $array['form_gram'];
    //let's construct the old paradigm
    $r = sql_fetch_array(sql_query("SELECT rev_text FROM dict_revisions WHERE lemma_id=".$array['lemma_id']." ORDER BY `rev_id` DESC LIMIT 1"));
    $pdr = parse_dict_rev($old_xml = $r['rev_text']);
    $lemma_text = $pdr['lemma']['text'];
    $old_paradigm = array();
    foreach($pdr['forms'] as $form_arr) {
        array_push($old_paradigm, array($form_arr['text'], implode(', ', $form_arr['grm'])));
    }
    $new_paradigm = array();
    foreach($ltext as $i=>$text) {
        $text = trim($text);
        if ($text == '') {
            //the form is to be deleted, so we do nothing
        } elseif (strpos($text, ' ') !== false) {
            die ("Error: a form cannot contain whitespace ($text)");
        } else {
            //TODO: perhaps some data validity check?
            array_push($new_paradigm, array($text, $lgram[$i]));
        }
    }
    //calculate which forms are actually updated
    $int = paradigm_diff($old_paradigm, $new_paradigm);
    //..and insert them into `updated_forms`
    $upd_forms = array();
    foreach($int as $int_form) {
        array_push($upd_forms, $int_form[0]);
    }
    $upd_forms = array_unique($upd_forms);
    foreach($upd_forms as $upd_form) {
        if (!sql_query("INSERT INTO `updated_forms` VALUES('".mysql_real_escape_string($upd_form)."')")) {
            die("Error at updated_forms :(");
        }
    }
    //array -> xml
    $new_xml = make_dict_xml($lemma_text, $new_paradigm);
    if ($new_xml != $old_xml) {
        //something's really changed
        $res = new_dict_rev($array['lemma_id'], $new_xml);
        if ($res) {
            header("Location:dict.php?act=edit&saved&id=".$array['lemma_id']);
        } else die("Error on saving");
    } else {
        header("Location:dict.php?act=edit&id=".$array['lemma_id']);
    }
}
function make_dict_xml($lemma_text, $paradigm) {
    $new_xml = '<dict_rev><lemma text="'.htmlspecialchars($lemma_text).'"/>';
    foreach($paradigm as $new_form) {
        list($txt, $gram) = $new_form;
        $new_xml .= '<form text="'.htmlspecialchars($txt).'">';
        $gram = explode(',', $gram);
        foreach($gram as $gr) {
            $new_xml .= '<grm val="'.htmlspecialchars(trim($gr)).'"/>';
        }
        $new_xml .= '</form>';
    }
    $new_xml .= '</dict_rev>';
    return $new_xml;
}
function new_dict_rev($lemma_id, $new_xml) {
    if (!$lemma_id || !$new_xml) return 0;
    $revset_id = create_revset();
    if (!$revset_id) return 0;
    if (sql_query("INSERT INTO `dict_revisions` VALUES(NULL, '$revset_id', '$lemma_id', '".mysql_real_escape_string($new_xml)."', '0')")) {
        return 1;
    }
    return 0;
}
function paradigm_diff($array1, $array2) {
    $diff = array();
    foreach($array1 as $form_array) {
        if(!in_array($form_array, $array2))
            array_push($diff, $form_array);
    }
    foreach($array2 as $form_array) {
        if(!in_array($form_array, $array1))
            array_push($diff, $form_array);
    }
    return $diff;
}

// GRAMMEM EDITOR
function dict_page_gram() {
    $out = '<p><a href="?">&lt;&lt;&nbsp;назад</a></p>';
    $out .= '<h2>Группы граммем</h2>';
    $out .= '<b>Добавить группу</b>: <form action="?act=add_gg" method="post" class="inline"><input name="g_name" value="&lt;Название&gt;">&nbsp;<input type="submit" value="Добавить"/></form><br/><br/>';
    $out .= '<b>Добавить граммему</b>:<br/><form action="?act=add_gram" method="post" class="inline">ID <input name="g_name" value="grm" size="10" maxlength="20"/>, AOT_ID <input name="aot_id" value="грм" size="10" maxlength="20"/>, группа <select name="group">'.dict_get_select_gramtype().'</select>,<br/>полное название <input name="descr" size="40"/> <input type="submit" value="Добавить"/></form><br/>';
    $out .= '<br/><table border="1" cellspacing="0" cellpadding="2"><tr><th>Название<th>AOT_id<th>Описание</tr>';
    $res = sql_query("SELECT gt.*, g.* FROM `gram_types` gt LEFT JOIN `gram` g ON (gt.type_id = g.gram_type) ORDER BY gt.`orderby`, g.`gram_name`");
    $last_group = '';
    while($r = sql_fetch_array($res)) {
        if ($last_group != $r['type_id']) {
            if ($last_group)
                $out.="</tr>\n";
            $out .= '<tr><td colspan="2"><b>'.$r['type_name']."</b><td>[<a href='#'>вверх</a>] [<a href='#'>вниз</a>]</tr>\n";
            $last_group = $r['type_id'];
        }
        if ($r['gram_id']) {
            $out .= '<tr><td>'.$r['gram_name']."<td>".$r['aot_id']."<td>".$r['gram_descr']."</tr>\n";
        }
    }
    $out .= '</table>';
    return $out;
}
function add_gramtype($name) {
    $r = sql_fetch_array(sql_query("SELECT MAX(`orderby`) AS `m` FROM `gram_types`"));
    if (sql_query("INSERT INTO `gram_types` VALUES(NULL, '$name', '".($r['m']+1)."')")) {
        header("Location:dict.php?act=gram");
    } else {
        //some error message
    }
}
function add_grammem($name, $group, $aot_id, $descr) {
    if (sql_query("INSERT INTO `gram` VALUES(NULL, '$group', '$aot_id', '$name', '$descr')")) {
        header("Location:dict.php?act=gram");
    } else {
        //some error message
    }
}

// ADDING TEXTS
function addtext_page($txt) {
    $out = '<h3>Добавляем текст</h3>';
    $out .= '<form action="?act=check" method="post"><textarea cols="70" rows="20" name="txt"'.(!$txt?' onClick="this.innerHTML=\'\'; this.onClick=\'\'">':'>').($txt?$txt:'Товарищ, помни! Абзацы разделяются двойным переводом строки, предложения &ndash; одинарным; предложение должно быть токенизировано.').'</textarea><br/>';
    $out .= '<br/><input type="submit" value="Проверить"/></form>';
    return $out;
}
function split2paragraphs($txt) {
    return preg_split('/\r?\n\r?\n\r?/', $txt);
}
function split2sentences($txt) {
    return preg_split('/[\r\n]+/', $txt);
}
function addtext_check($txt) {
    $out = '<form action="?" method="post" class="inline"><textarea style="display: none" name="txt">'.htmlspecialchars($txt).'</textarea><a href="#" onClick="document.forms[0].submit()">Обратно к форме</a></form><ol type="I">';
    $pars = split2paragraphs($txt);
    foreach ($pars as $par) {
        $out .= '<li><ol>';
        $sents = split2sentences($par);
        foreach ($sents as $sent) {
            $out .= '<li>';
            $tokens = explode(' ', $sent);
            foreach ($tokens as $token) {
                $ex = form_exists($token);
                if ($ex == -1) {
                    $out .= "<span class='check_unpos'>$token</span> ";
                } elseif (!$ex) {
                    $out .= "<span class='check_noword'>$token</span> ";
                } else {
                    $out .= "$token ";
                }
            }
            $out .= '</li>';
        }
        $out .= "</ol></li>\n";
    }
    $out .= '</ol>';
    $out .= '<form action="?act=add" method="post">Добавляем в <select id="book0" name="book[]" onChange="changeSelectBook(0)"><option value="0">-- Не выбрано --</option>'.books_get_select(0).'</select>&nbsp;';
    $out .= '<select id="book1" name="book[]" disabled="disabled" onChange="changeSelectBook(1)"><option value="0">-- Не выбрано --</option></select>';
    $out .= '<br/><p id="lastpar_info">Надо выбрать книгу.</p>';
    $out .= '<textarea style="display: none" name="txt">'.htmlspecialchars($txt).'</textarea>';
    $out .= 'Счёт абзацев &ndash; с <input id="newpar" name="newpar" size="3" maxlength="3" value="1"/>&nbsp;<input id="submitter" type="submit" value="Добавить" disabled="disabled"/></form>';
    return $out;
}
function addtext_add($text, $book_id, $par_num) {
    if (!$text || !$book_id || !$par_num) return 0;
    $revset_id = create_revset();
    if (!$revset_id) return 0;
    $pars = split2paragraphs($text);
    foreach($pars as $par) {
        //adding a paragraph
        if (!sql_query("INSERT INTO `paragraphs` VALUES(NULL, '$book_id', '".($par_num++)."')")) return 0;
        $par_id = sql_insert_id();
        $sent_num = 1;
        $sents = split2sentences($par);
        foreach($sents as $sent) {
            //adding a sentence
            if (!sql_query("INSERT INTO `sentences` VALUES(NULL, '$par_id', '".($sent_num++)."', '0')")) return 0;
            $sent_id = sql_insert_id();
            $token_num = 1;
            //strip excess whitespace
            $sent = preg_replace('/\s\s+/', ' ', $sent);
            $tokens = explode(' ', $sent);
            foreach ($tokens as $token) {
                //adding a textform
                if (!sql_query("INSERT INTO `text_forms` VALUES(NULL, '$sent_id', '".($token_num++)."', '".mysql_real_escape_string($token)."', '0')")) return 0;
                $tf_id = sql_insert_id();
                //adding a revision
                if (!sql_query("INSERT INTO `tf_revisions` VALUES(NULL, '$revset_id', '$tf_id', '".mysql_real_escape_string(generate_tf_rev($token))."')")) return 0;
            }
        }
    }
    return 1;
}
?>
