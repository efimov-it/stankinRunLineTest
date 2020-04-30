<?php
Core::disableDirectCall();

class API {
    private static $className = "API";
    private        $Core;

    private $error_counter;
    private $error_message;
    private $error_type;
    private $error_pull;

    private $result;

    private $action;
    private $data;
    private $response_body;
    private $in;

    private $decoded_token;

    public function listenInput() {
        $this->Core = Core::getInstance();
        $this->Core->Database->pdo();

        $input_object = null;
        if (stristr($_SERVER['CONTENT_TYPE'], "json"))
            $input_object = file_get_contents('php://input');
        else
            $input_object = $_POST['body'];

        if ($input_object === null) {
            $this->error("Input object not found.", E_USER_WARNING);
            exit(1);
        }

        $decode_object = json_decode($input_object);
        $this->in = get_object_vars($decode_object);
        $this->result = null;

        $access_list = json_decode(file_get_contents(_SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . 'access_list.json'));
        if (in_array($this->in['action'], $access_list->user_only)) {
            if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $decoded_token = JWT::decode($_SERVER['HTTP_AUTHORIZATION'], SECURE_KEY, ['HS256']);
                $this->decoded_token = $decoded_token;
                $role = $decoded_token->role;

                $this->_writeUserLog($decoded_token->id);
            }
            if (!isset($decoded_token)) exit("Access denied!");
        }

        if ($this->in['action'] !== '' && isset($this->in['data'])) {
            $this->action = "_ix_" . $this->in['action'];
            $this->data = $this->in['data'];
            if (method_exists("API", $this->action)) {
                $this->result = call_user_func([$this, $this->action]);
            } else {
                $error = "Non-existent api method. Method: " . $this->action;
                trigger_error($error);
            }
        } else {
            $error = "Invalid request body. Data: " . $input_object;
            trigger_error($error);
        }

        if ($this->result !== null)
            $this->_sendResponse();
        else {
            $error = "Internal error.";
            trigger_error($error);
        }
    }

    private function error($error_msg, $error_type = E_USER_WARNING) {
        $this->error_counter++;
        $this->error_message = $error_msg;
        $this->error_type = $error_type;

        trigger_error($error_msg, $error_type);
    }

    private function _sendResponse() {
        if ($this->result !== false) {
            $this->_buildResponseBody($this->result, true, '');
        } else {
            $this->_buildResponseBody($this->result, false, $this->error_message);
        }

        echo json_encode($this->response_body);
    }

    private function _isResponseOK($response) {
        if ($response === false || $response == null || $response == '') {
            $db_error = $this->Core->Database->getLastError();
            $this->error($db_error);
            return false;
        } else return $response;
    }

    private function _buildResponseBody($data = '', $success = false, $error = 'Access denied.') {
        $this->response_body = array('success' => $success, 'data' => $data, 'error' => $error);
    }

    public function __call($name, $post) {
        $error = "Non-existent api method. Method: " . $name . ", Params: " . json_encode($post);
        trigger_error($error, E_USER_WARNING);
    }

    public function _ix_ping() {
        return "PONG!";
    }

     function _ix_getInfo() {
        $git_last_short_hash = exec("git rev-parse --short=8 HEAD");
        $hostname = gethostname();
        $local_ip = gethostbyname($hostname);
        $responseIP = file_get_contents('http://checkip.dyndns.com/');
        preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $responseIP, $m);
        $global_ip = $m[0];
        return [
            "last_commit_hash" => $git_last_short_hash,
            "hostname" => $hostname,
            "local_ip" => $local_ip,
            "global_ip" => $global_ip,
            "db_addr" => DB_HOST
        ];
     }

     private function _getActionTable() {

        $action_tables = json_decode(file_get_contents(_SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . 'action_tables.json'));
        $result = '';

        foreach($action_tables->table_actions as $action_table) {
            if(in_array($this->in['action'], $action_table->methods)) {
                $result .= $action_table->methods[0] . ' ';
            }
        }

        return $result;
    }

    private function _writeUserLog($user_id) {
        $action_table = $this->_getActionTable();

        $move_type = substr($this->in['action'], 0, 3);
        if($move_type == 'edi') {
            $move_type = 'edit';
        }
        else if($move_type == 'upl') {
            $move_type = 'upload';
        }
        else if($move_type == 'bui') {
            $move_type = 'build';
        }
        else if($move_type == 'del') {
            $move_type = 'delete';
        }
        else if($move_type == 'add') {
            $move_type = 'add';
        }
        else if($move_type == 'pin') {
            $move_type = 'ping';
        }

        $data = [
            'user_id' => $user_id,
            'move_type' => $move_type,
            'action' => $this->in['action'],
            'action_table' => $action_table,
            'date' => date("Y-m-d H:i:s"),
        ];

        $this->Core->Database->insert('history', $data);
    }

    private function _checkHtml($html_string) {
        $filter = $this->Core->HTMLFilter->filter($html_string);
        if(!$filter['success']) {
            return $filter;
        }

        return true;
    }

    public function _ix_getMeta() {
        $meta = file_get_contents(_SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . 'meta.json');
        $meta = json_decode($meta);
        return $meta;
    }

    public function _ix_setMeta() {
        $meta = json_decode($this->data->meta, true);

        foreach ($meta as $array_num => $tag) {
            foreach ($tag as $key => $value) {
                $protected_meta[$array_num][htmlspecialchars($key)] = htmlspecialchars($value);
            }
        }

        $response = file_put_contents(_SYSTEM_CORE_ROOT_ . DIRECTORY_SEPARATOR . 'meta.json', json_encode($protected_meta));
        return $response;
    }

    public function _ix_auth() {
        $user = $this->Core->Database->where('login', $this->data->login)->getOne('users', 'id, password, login, role');
        if (password_verify($this->data->password, $user['password'])) {
            $token_vars = [
                'id' => $user['id'],
                'role' => 'user',
                'exp' => strtotime("+1 day")
            ];

            $token = JWT::encode($token_vars, SECURE_KEY);

            header("Authorization: $token");

            $response = [
                "status" => "ok",
                "user_info" => [
                    "id" => $user['id'],
                    "login" => $user['login'],
                    "role" => $user['role']
                ]
            ];
        } else {
            $response = ["status" => "wrong password!"];
        }
        return $response;
    }

    public function _ix_contactUs() {
        if (!$this->data->accept) {
            $this->error("Необходимо подтвердить обработку данных!");
            return false;
        }
        $data = "Человек с именем {$this->data->name}, нуждается в нашей обратной связи с ним...\n Да будет так! <b>Email:<b> {$this->data->email}\n Ах да и еще он оставил комментарий: {$this->data->comment}";
        $response = mail("info@ivnix.com", "Stankin Feedback", $data);
        if ($this->_isResponseOK($response)) {
            return true;
        } else {
            $this->error("Произошла ошибка отправки письма через SMTP...");
            return false;
        }
    }

    public function _ix_subscribe() {
        $data = [
            'name' => $this->data->name,
            'email' => $this->data->email
        ];
        $response = $this->Core->Database->insert('subscribers', $data);
        return ($this->_isResponseOK($response)) ? true : false;
    }

    private function _uploadFiles($prefix = "file") {
        $prefix .= "_";

        if (empty($_FILES)) return null;

        $file_count = count($_FILES['file']['name']);
        $error_counter = 0;
        $errors = array();
        $files = array();

        for ($i = 0; $i < $file_count; $i++) {
            $filename = $_FILES['file']['name'][$i];
            $file_path = $_FILES['file']['tmp_name'][$i];
            $error_code = $_FILES['file']['error'][$i];

            if ($error_code !== UPLOAD_ERR_OK || !is_uploaded_file($file_path)) {
                $error_messages = [
                    UPLOAD_ERR_INI_SIZE => 'Размер файла превысил значение upload_max_filesize в конфигурации PHP.',
                    UPLOAD_ERR_FORM_SIZE => 'Размер загружаемого файла превысил значение MAX_FILE_SIZE в HTML-форме.',
                    UPLOAD_ERR_PARTIAL => 'Загружаемый файл был получен только частично.',
                    UPLOAD_ERR_NO_FILE => 'Файл не был загружен.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Отсутствует временная папка.',
                    UPLOAD_ERR_CANT_WRITE => 'Не удалось записать файл на диск.',
                    UPLOAD_ERR_EXTENSION => 'PHP-расширение остановило загрузку файла.',
                ];
                $unknown_message = 'При загрузке файла произошла неизвестная ошибка.';
                $err_msg = isset($error_messages[$error_code]) ? $error_messages[$error_code] : $unknown_message;
                $this->error($err_msg);

                return false;
            }

            $ext = array_reverse(explode(".", $filename))[0];

            # TODO: переписать в access-list
            if ($ext == 'php' || $ext == 'PHP') {
                $this->error("Не допускается загрузка исполняемых файлов");
                return false;
            }

            do {
                $name = uniqid($prefix);
                $final_path = join(DIRECTORY_SEPARATOR, [_SYSTEM_ROOT_FOLDER_,'uploads','files',"$name.$ext"]);
            } while (file_exists($final_path) != false);

            if (!move_uploaded_file($file_path, $final_path)) {
                $error_counter++;
                $errors[] = 'При записи файла на диск произошла ошибка.';
            }

            $files[] = DIRECTORY_SEPARATOR . join(DIRECTORY_SEPARATOR, ['uploads','files',"$name.$ext"]);
        }

        if ($error_counter > 0) {
            $this->error(implode(", ", $errors));

            return false;
        }

        return (empty($files[0])) ? null : $files;
    }

    private function _ix_upload() {
        $files = $this->_uploadFiles();

        return ($this->_isResponseOK($files)) ? $files : false;
    }

    private function _ix_uploadPhoto() {
        $files = $this->_uploadFiles($this->data->type . $this->data->id);

        if ($files == null) {
            $this->error('Не удалось загрузить картинку...');
            return false;
        } else return ["link" => $files[0]];
    }

    private function _ix_search() {
        if (!isset($this->data->query)) {
            $this->error("Параметр query обязателен!");
            return false;
        } else $query_search = '%' . $this->data->query . '%';

        $query_strings = [
            "announces" => "SELECT id, title, json_build_object('type', 'announce') as payload FROM announces WHERE title ILIKE ?",
            "news" => "SELECT id, title, json_build_object('type', 'news') as payload FROM news WHERE title ILIKE ?",
            "documents" => "SELECT id, name, json_build_object('type', 'document', 'link', subject.link) as payload FROM documents AS subject WHERE name ILIKE ?",
            "subdivisions" => "SELECT id, name, json_build_object('type', 'subdivision') as payload FROM subdivisions WHERE name ILIKE ?",
            "users" => "SELECT id, fullname, json_build_object('type', 'user', 'subdivision_id', subject.subdivision_id) as payload FROM users AS subject WHERE fullname ILIKE ?",
            "pages" => "SELECT id, name, json_build_object('type', 'page','category_id', (SELECT id FROM template_page WHERE submenu_id = (SELECT id FROM template_category_submenu WHERE (SELECT menu_item_id FROM pages WHERE id = subject.id) = ANY (items)))) as payload FROM pages as subject WHERE name ILIKE ?"
        ];

        if (isset($this->data->type)) {
            if (isset($query_strings[$this->data->type])) {
                if (isset($this->data->count) && isset($this->data->page)) {
                    switch ($this->data->type) {
                        case 'announces':
                            $count = $this->Core->Database->querySingle("SELECT count(*) FROM announces WHERE title ILIKE ?", [$query_search])['count'];
                            break;
                        case 'news':
                            $count = $this->Core->Database->querySingle("SELECT count(*) FROM news WHERE title ILIKE ?", [$query_search])['count'];
                            break;
                        case 'documents':
                            $count = $this->Core->Database->querySingle("SELECT count(*) FROM documents WHERE title ILIKE ?", [$query_search])['count'];
                            break;
                        case 'subdivisions':
                            $count = $this->Core->Database->querySingle("SELECT count(*) FROM subdivisions WHERE name ILIKE ?", [$query_search])['count'];
                            break;
                        case 'users':
                            $count = $this->Core->Database->querySingle("SELECT count(*) FROM users WHERE fullname ILIKE ?", [$query_search])['count'];
                            break;
                        case 'pages':
                            $count = $this->Core->Database->querySingle("SELECT count(*) FROM pages WHERE name ILIKE ?", [$query_search])['count'];
                            break;
                    }
                    $offset = $this->data->count * ($this->data->page - 1);
                    $result['founded'] = $this->Core->Database
                        ->query($query_strings[$this->data->type] . " OFFSET ? LIMIT ?", [
                            $query_search,
                            $offset,
                            $this->data->count
                    ]);
                    $result['count'] = ceil($count / $this->data->count);
                    if ($result['count'] == 0) $result['count'] = 1;
                } else {
                    $result['founded'] = $this->Core->Database->query($query_strings[$this->data->type], [$query_search]);
                }
            } else {
                $this->error("Unknown type!");
                return false;
            }
        } else {
            $result['founded'] = $this->Core->Database->query(join(" UNION ALL ", $query_strings), [
                $query_search,
                $query_search,
                $query_search,
                $query_search,
                $query_search,
                $query_search
            ]);
        }

        foreach ($result['founded'] as &$value) {
            $value['payload'] = json_decode($value['payload']);
        }

        return $result;
    }

    private function _ix_addNews() {
        $files = $this->_uploadFiles();
        if ($files == null) $files = ["/uploads/files/default.jpg"];

        $data = [
            "title" => $this->data->title,
            "date" => $this->data->date,
            "delta" => $this->data->delta,
            "short_text" => $this->data->short_text,
            "logo" => $files[0],
            "pull_site" => $this->data->pull_site,
            "is_main" => $this->data->is_main,
            "tags" => '{' . $this->data->tags . '}',
            "subdivision_id" => $this->data->subdivision_id
        ];

        $filter = $this->_checkHtml(json_decode($data['delta'])->html);
        if($filter !== true) {
            return $filter;
        }

        $response = $this->Core->Database->insert("news", $data, 'id');

        $subscribers = $this->Core->Database->get('subscribers');

        foreach ($subscribers as $subscriber) {
            $to  = "<" . $subscriber['email'] .">" ;

            $subject = "Новости МГТУ «Станкин»";

            $message = ' <p>' . $this->data->title . '</p> </br></br> <p> https://stankin.dev.ivnix.com/news/item_' . $response . '</p>';

            $headers  = "Content-type: text/html; charset=utf-8 \r\n";

            mail($to, $subject, $message, $headers);
        }

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _getNewsBySubdivisionId($id) {
        $response = $this->Core->Database->where('subdivision_id', $id)->orderBy('date', 'DESC')->get('news');
        return $response;
    }

    private function _ix_getNews($subdivision_id = null) {
        $offset = $this->data->count * ($this->data->page - 1);
        $response = null;

        $query_search = '%';
        if (isset($this->data->query_search)) {
            $query_search .= $this->data->query_search . '%';
        }

        if (!empty($this->data->tag)) {
            $count = $this->Core->Database
                ->querySingle("SELECT count(*) FROM news WHERE (SELECT id FROM tags WHERE type = 0 AND name = ?) = ANY (tags) AND title ILIKE ?", [$this->data->tag, $query_search])['count'];
            $response = $this->Core->Database
                ->query("SELECT id, title, date, logo, tags, short_text, author_id FROM news WHERE (SELECT id FROM tags WHERE type = 0 AND name = ?) = ANY (tags) AND title ILIKE ? OFFSET ? LIMIT ?", [$this->data->tag, $query_search, $offset, $this->data->count]);
        } elseif ($this->data->is_main) {
            $count = $this->Core->Database
                ->where('is_main')
                ->where('title', $query_search, 'ILIKE')
                ->count('news');
            $response = $this->Core->Database
                ->where('is_main')
                ->where('title', $query_search, 'ILIKE')
                ->orderBy('date', 'DESC')
                ->get('news', [$offset, $this->data->count], 'id, title, date, logo, tags, short_text, author_id');
        } elseif ($this->data->pull_site) {
            $count = $this->Core->Database
                ->where('pull_site')
                ->where('title', $query_search, 'ILIKE')
                ->count('news');
            $response = $this->Core->Database
                ->where('pull_site')
                ->where('title', $query_search, 'ILIKE')
                ->orderBy('date', 'DESC')
                ->get('news', [$offset, $this->data->count], 'id, title, date, logo, tags, short_text, author_id');
        } else {
            if ($subdivision_id != null) $this->data->subdivision_id = $subdivision_id;
            $count = $this->Core->Database
                ->where('subdivision_id', $this->data->subdivision_id)
                ->where('title', $query_search, 'ILIKE')
                ->count('news');
            $response = $this->Core->Database
                ->where('subdivision_id', $this->data->subdivision_id)
                ->where('title', $query_search, 'ILIKE')
                ->orderBy('date', 'DESC')
                ->get('news', [$offset, $this->data->count], 'id, title, date, logo, short_text, author_id');
        }

        $count = ceil($count / $this->data->count);
        if ($count == 0) $count = 1;

        return ["news" => $response, "count" => $count];
    }

    private function _ix_getAllNews() {
        $news = $this->Core->Database->orderBy('date', 'DESC')->get('news', null, 'id, title');
        return ["news" => $news];
    }

    private function _ix_delNewsItem() {
        $news_id = (!empty($this->data->id)) ? $this->data->id : null;
        if (empty($news_id)) {
            $this->error("Param ID not be empty!");

            return false;
        }
        $link = $this->Core->Database->where('id', $news_id)->getOne('news')['logo'];

        if (file_exists(_SYSTEM_ROOT_FOLDER_ . $link) && ($link != "/uploads/files/default.jpg") && ($link != '/')) {
            if (unlink(_SYSTEM_ROOT_FOLDER_ . $link) == false) {
                $this->error("Проблемы с удалением файла на сервере!");
                return false;
            }
        }

        $response = $this->Core->Database->where('id', $news_id)->delete("news");
        # TODO: сделать удаление лого
        if ($response == '' || $response == null) {
            $this->error("This news [$news_id] already deleted");

            return false;
        } elseif ($response === false) {
            $db_error = $this->Core->Database->getLastError();
            $this->error($db_error);

            return false;
        } else {
            return $response;
        }
    }

    private function _ix_editNewsItem() {
        $logo = "";
        if ($this->data->logo) $logo = $this->_uploadFiles()[0];

        $data = [
            "title" => $this->data->title,
            "date" => $this->data->date,
            "delta" => $this->data->delta,
            "short_text" => $this->data->short_text,
            "is_main" => $this->data->is_main,
            "pull_site" => $this->data->pull_site,
            "subdivision_id" => $this->data->subdivision_id,
            "tags" => "{" . $this->data->tags . "}",
            "logo" => $logo
        ];

        $filter = $this->_checkHtml(json_decode($data['delta'])->html);
        if($filter !== true) {
            return $filter;
        }

        if ($logo == "")
            unset($data["logo"]);

        $response = $this->Core->Database->where('id', $this->data->id)->update("news", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _getSlide($id) {
        $response = $this->Core->Database->where('id', $id)->orderBy('id')->get('slides');
        return $response[0];
    }

    private function _ix_getSliders() {
        $sliders = $this->Core->Database->orderBy('id')->get('sliders');
        $result = array("sliders" => array());

        foreach ($sliders as $id) {
            $result['sliders'][] = $this->_ix_getSlider($id['id']);
        }

        return $result;
    }

    private function _ix_getSlider($id = null) {
        if ($id !== null) {
            $slider = $this->Core->Database->where('id', $id)->getOne('sliders');
        } else {
            $slider =$this->Core->Database->where('id', $this->data->id)->orderBy('id')->get('sliders');
        }

        $slider_array = $this->_convertStringToArray($slider['slides']);
        $response = array();

        foreach ($slider_array as $id_slide) {
            $response[] = $this->_getSlide($id_slide);
        }

        return ["slides" => $response, "id" => ($id !== null) ? $id : $this->data->id, "name" => $slider['name']];
    }

    private function _addSlide($text, $image, $link, $color) {
        $data = ["text" => $text, "image" => $image, "link" => $link, "color" => $color];
        $response = $this->Core->Database->insert("slides", $data, "id");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editQueueSlider() {
        $parent = $this->Core->Database->where('id', $this->data->slider_id)->getOne('sliders');

        $response = $this->Core->Database->query("UPDATE sliders SET slides = '{" . implode(', ', $this->data->slides) . "}' WHERE id = {$parent['id']}");

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addSlide() {
        $image = $this->_uploadFiles();
        if ($image[0] == null) $image = ['/uploads/files/default.jpg'];

        $slide = null;
        if (!$slide = $this->_addSlide($this->data->text, $image[0], $this->data->link, $this->data->color)) return false;
        $response = $this->Core->Database->query("UPDATE sliders SET slides = array_append(slides, {$slide}::integer) WHERE id = {$this->data->slider_id}");

        if ($this->_isResponseOK($response)) {
            $response['image'] = $image;
            $response['id'] = $slide;
            return $response;
        } else return false;
    }

    private function _ix_editSlide() {
        $image = $this->_uploadFiles();

        $response = null;
        if ($image[0] == null) {
            $response = $this->Core->Database->query("UPDATE slides SET color = ?, text = ?, link = ? WHERE id = ?",
                                                     [$this->data->color, $this->data->text, $this->data->link, $this->data->slide_id]
            );
        } else {
            $response = $this->Core->Database->query("UPDATE slides SET color = ?, text = ?, link = ?, image = '{$image[0]}' WHERE id = ?",
                                                     [$this->data->color, $this->data->text, $this->data->link, $this->data->slide_id]
            );
        }
        return ($this->_isResponseOK($response)) ? ["image" => $image[0]] : false;
    }

    private function _ix_deleteSlide() {
        $response = $this->Core->Database->where('id', $this->data->slide_id)->delete('slides');
        $response = $this->Core->Database->query("UPDATE sliders SET slides = array_remove(slides, ?) WHERE id = ?", [$this->data->slide_id, $this->data->slider_id]);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addTags() {
        $data = ["name" => $this->data->name, "type" => $this->data->type];
        $response = $this->Core->Database->insert("tags", $data, 'id');
        return ($this->_isResponseOK($response)) ? ['id' => $response] : false;
    }

    private function _ix_getTags() {
        $tags = $this->Core->Database->where('type', $this->data->type)->orderBy('id')->get('tags');
        return ["tags" => $tags];
    }

    private function _ix_delTags() {
        $announces = $this->Core->Database->where("? = ANY (tags)", $this->data->id)->get("announces", null, "id");
        foreach ($announces as $announce) {
            $this->Core->Database->query("UPDATE announces SET tags = array_remove(tags, ?) WHERE id = ?", [$this->data->id, $announce['id']]);
        }
        $news = $this->Core->Database->where("? = ANY (tags)", $this->data->id)->get("news", null, "id");
        foreach ($news as $new) {
            $this->Core->Database->query("UPDATE news SET tags = array_remove(tags, ?) WHERE id = ?", [$this->data->id, $new['id']]);
        }
        $documents = $this->Core->Database->where('type', $this->data->id)->get("documents", null, "id");
        foreach ($documents as $doc) {
            $this->Core->Database->where('id', $doc['id'])->update("documents", ["type" => null]);
        }
        $response = $this->Core->Database->where('id', $this->data->id)->delete("tags");
        if ($this->_isResponseOK($response)) {
            return $response;
        } else {
            $this->error("Данный тег уже был удален!");
            return false;
        }
    }

    private function _ix_editTags() {
        $data = ["name" => $this->data->name, "type" => $this->data->type];
        $response = $this->Core->Database->where('id', $this->data->id)->update("tags", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addAnnounce() {
        $files = $this->_uploadFiles();
        if ($files == null) $files = ["/uploads/files/default.jpg"];

        $data = [
            "title" => $this->data->title,
            "subtitle" => $this->data->subtitle,
            "type" => $this->data->type,
            "delta" => $this->data->delta,
            "logo" => $files[0],
            "event_start_time" => $this->data->event_start_time,
            "event_start" => $this->data->event_start,
            "event_end" => $this->data->event_end,
            "registration_end" => $this->data->registration_end,
            "link" => $this->data->link,
            "address" => $this->data->address,
            "pull_site" => $this->data->pull_site,
            "is_main" => $this->data->is_main,
            "tags" => '{' . $this->data->tags . '}',
            "subdivision_id" => $this->data->subdivision_id,
            "left_links" => $this->data->left_links
        ];

        $filter = $this->_checkHtml(json_decode($data['delta'])->html);
        if($filter !== true) {
            return $filter;
        }

        $response = $this->Core->Database->insert("announces", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    // private function _getAnnouncesBySubdivisionId($id) {
    //     $response = $this->Core->Database->where('subdivision_id', $id)->orderBy('date', 'DESC')->get('announces');
    //     return ($this->_isResponseOK($response)) ? $response : false;
    // }

    private function _ix_getAnnounces($subdivision_id = null) {
        $offset = $this->data->count * ($this->data->page - 1);
        $response = null;

        $query_search = '%';
        if (isset($this->data->query_search)) {
            $query_search .= $this->data->query_search . '%';
        }

        if (!empty($this->data->tag)) {
            $count = $this->Core->Database
                ->querySingle("SELECT count(*) FROM announces WHERE (SELECT id FROM tags WHERE type = 1 AND name = ?) = ANY (tags) AND title ILIKE ?", [$this->data->tag, $query_search])['count'];
            $response = $this->Core->Database
                ->query("SELECT * FROM announces WHERE (SELECT id FROM tags WHERE type = 1 AND name = ?) = ANY (tags) AND title ILIKE ? OFFSET ? LIMIT ?", [$this->data->tag, $query_search, $offset, $this->data->count]);
        } elseif ($this->data->is_main) {
            $count = $this->Core->Database
                ->where('is_main')
                ->where('title', $query_search, 'ILIKE')
                ->count('announces');
            $response = $this->Core->Database
                ->where('is_main')
                ->where('title', $query_search, 'ILIKE')
                ->orderBy('date', 'DESC')
                ->get('announces', [$offset, $this->data->count]);
        } elseif ($this->data->pull_site) {
            $count = $this->Core->Database
                ->where('pull_site')
                ->where('title', $query_search, 'ILIKE')
                ->count('announces');
            $response = $this->Core->Database
                ->where('pull_site')
                ->where('title', $query_search, 'ILIKE')
                ->orderBy('date', 'DESC')
                ->get('announces', [$offset, $this->data->count]);
        } else {
            if ($subdivision_id != null) $this->data->subdivision_id = $subdivision_id;
            $count = $this->Core->Database
                ->where('subdivision_id', $this->data->subdivision_id)
                ->where('title', $query_search, 'ILIKE')
                ->count('announces');
            $response = $this->Core->Database
                ->where('subdivision_id', $this->data->subdivision_id)
                ->where('title', $query_search, 'ILIKE')
                ->orderBy('date', 'DESC')
                ->get('announces', [$offset, $this->data->count]);
        }

        $count = ceil($count / $this->data->count);
        if ($count == 0) $count = 1;

        return ["announces" => $response, "count" => $count];
    }

    private function _ix_editAnnounce() {
        $logo = "";
        if ($this->data->logo) $logo = $this->_uploadFiles()[0];

        $data = [
            "title" => $this->data->title,
            "subtitle" => $this->data->subtitle,
            "type" => $this->data->type,
            "delta" => $this->data->delta,
            "logo" => $logo,
            "event_start_time" => $this->data->event_start_time,
            "event_start" => $this->data->event_start,
            "event_end" => $this->data->event_end,
            "registration_end" => $this->data->registration_end,
            "link" => $this->data->link,
            "address" => $this->data->address,
            "pull_site" => $this->data->pull_site,
            "is_main" => $this->data->is_main,
            "tags" => '{' . $this->data->tags . '}',
            "subdivision_id" => $this->data->subdivision_id,
            "left_links" => $this->data->left_links
        ];

        $filter = $this->_checkHtml(json_decode($data['delta'])->html);
        if($filter !== true) {
            return $filter;
        }

        if ($logo == "") unset($data["logo"]);

        $response = $this->Core->Database->where('id', $this->data->id)->update("announces", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delAnnounce() {
        $response = $this->Core->Database->where("id", $this->data->id)->delete("announces");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getAllAnnounces() {
        $announces = $this->Core->Database->orderBy('date','DESC')->get('announces', null, 'id, title');
        return ["announces" => $announces];
    }

    private function _ix_addSubdivision() {
        $data = [
            "name" => $this->data->name,
            "delta" => $this->data->delta,
            "config" => $this->data->config,
            "parent" => 0,
            "delta_history" => $this->data->deltaHistory,
            "visible" => $this->data->visible,
        ];

        $filter = $this->_checkHtml(json_decode($data['delta'])->html);
        if($filter !== true) {
            return $filter;
        }
        $filter = $this->_checkHtml(json_decode($data['delta_history'])->html);
        if($filter !== true) {
            return $filter;
        }

        $response = $this->Core->Database->insert("subdivisions", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editSubdivision() {
        $data = [
            "name" => $this->data->name,
            "delta" => $this->data->delta,
            "config" => $this->data->config,
            "delta_history" => $this->data->deltaHistory,
            "visible" => $this->data->visible,
        ];

        $filter = $this->_checkHtml(json_decode($data['delta'])->html);
        if($filter !== true) {
            return $filter;
        }
        $filter = $this->_checkHtml(json_decode($data['delta_history'])->html);
        if($filter !== true) {
            return $filter;
        }

        $response = $this->Core->Database->where("id", $this->data->id)->update("subdivisions", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editQueueSubdivision() {
        $parent_from = $this->Core->Database->where('num', $this->data->from->num)->getOne('subdivisions', 'parent')['parent'];
        $parent_to = $this->Core->Database->where('num', $this->data->to->num)->getOne('subdivisions', 'parent')['parent'];

        $this->Core->Database->where('parent', $this->data->from->num)->update('subdivisions', ['parent' => -7]);
        $this->Core->Database->where('parent', $this->data->to->num)->update('subdivisions', ['parent' => $this->data->from->num]);
        $this->Core->Database->where('parent', -7)->update('subdivisions', ['parent' => $this->data->to->num]);

        $this->Core->Database->where('num', $this->data->to->num)->update('subdivisions', ['parent' => $parent_from]);
        $this->Core->Database->where('num', $this->data->from->num)->update('subdivisions', ['parent' => $parent_to]);

        $this->Core->Database->where('num', $this->data->from->num)->update('subdivisions', ['num' => -7]);
        $this->Core->Database->where('num', $this->data->to->num)->update('subdivisions', ['num' => $this->data->from->num]);
        $this->Core->Database->where('num', -7)->update('subdivisions', ['num' => $this->data->to->num]);

        return true;
    }

    private function _ix_editQueueSubdivisionUser() {
        $from = $this->Core->Database->where('id', $this->data->from->id)->getOne('subdivision_user', 'subdivision_id');
        $to = $this->Core->Database->where('id', $this->data->to->id)->getOne('subdivision_user', 'subdivision_id');

        if ($from['subdivision_id'] == $to['subdivision_id']) {
            $this->Core->Database->where('id', $this->data->from->id)->update('subdivision_user', ['id' => -7]);
            $this->Core->Database->where('id', $this->data->to->id)->update('subdivision_user', ['id' => $this->data->from->id]);
            $this->Core->Database->where('id', -7)->update('subdivision_user', ['id' => $this->data->to->id]);
        } else {
            $this->error('Указаны разные подразделения');
            return false;
        }

        return true;
    }

    private function _buildTree($items) {
        $childs = array();

        foreach ($items as &$item)
            $childs[$item['parent']][] = &$item;
        unset($item);

        foreach ($items as &$item)
            if (isset($childs[$item['num']]))
                $item['subs'] = $childs[$item['num']];

        return $childs[1];
    }

    private function _ix_getSubdivision() {
        $response = $this->Core->Database->where('id', $this->data->id)->getOne('subdivisions');
        $subdivision_important = $this->Core->Database->where("subdivision_id", $this->data->id)
            ->where("important", true)->getOne('subdivision_user');

        $response['important'] = [
            'user' => $this->Core->Database->where('id', $subdivision_important['user_id'])->getOne('users', 'id, email, phone, avatar, address, fullname'),
            'subdivisions' => [$subdivision_important],
        ];
        return $response;
    }

    private function _ix_getSubdivisions() {
        $tree = array();
        $buffer = array();
        if ($this->data->tree) {
            $response = $this->Core->Database
                ->where("parent != 0")
                ->where('visible', true)
                ->orderBy('num')
                ->get('subdivisions');
            $root = $this->Core->Database
                ->where("parent", 0)
                ->where("num", 1)
                ->where('visible', true)
                ->orderBy('num')
                ->get('subdivisions');
            $tree = $root[0];
            if ($this->_isResponseOK($response)) {
                $tree['subs'] = $this->_buildTree($response);
            } else $tree['subs'] = array();
        }

        if ($this->data->buffer) {
            $buffer = $this->Core->Database
                ->where("parent = 0")
                ->where("id != 1")
                ->where('visible', true)
                ->get('subdivisions');
        }

        return ["tree" => $tree, "buffer" => $buffer];
    }

    private function _ix_getAllSubdivisions() {
        $tree = array();
        $buffer = array();
        if ($this->data->tree) {
            $response = $this->Core->Database
                ->where("parent != 0")
                ->orderBy('num')
                ->get('subdivisions');
            $root = $this->Core->Database
                ->where("parent", 0)
                ->where("num", 1)
                ->orderBy('num')
                ->get('subdivisions');
            $tree = $root[0];
            if ($this->_isResponseOK($response)) {
                $tree['subs'] = $this->_buildTree($response);
            } else $tree['subs'] = array();
        }

        if ($this->data->buffer) {
            $buffer = $this->Core->Database
                ->where("parent = 0")
                ->where("id != 1")
                ->get('subdivisions');
        }

        return ["tree" => $tree, "buffer" => $buffer];
    }

    private function _ix_buildTreeSubdivisions() {
        if ($this->data->is_add) {
            $response = $this->Core->Database
                ->where('id', $this->data->sub_id)
                ->update('subdivisions', ['parent' => $this->data->parent_id]);
        } else {
            foreach ($this->data->buffer as $id) {
                $response = $this->Core->Database
                    ->where('id', $id)
                    ->update('subdivisions', ['parent' => 0]);
            }
        }
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getNewsItem() {
        $response = $this->Core->Database->where("id", $this->data->id)->getOne("news");
        $response["delta"] = json_decode($response["delta"], true);
        return $response;
    }

    private function _ix_getAnnouncesItem() {
        $response = $this->Core->Database->where("id", $this->data->id)->getOne("announces");
        $response["delta"] = json_decode($response["delta"], true);
        return $response;
    }

    private function _ix_getSubdivisionsList() {
        $response = $this->Core->Database->orderBy('id')->get('subdivisions');
        return $response;
    }

    private function _ix_editPositionMenu() {
        $menus = $this->Core->Database
            ->orWhere('id', 1)
            ->orWhere('id', 2)
            ->orWhere('id', 3)
            ->get('menu_item', null, 'id, submenu');
        $submenu = $this->Core->Database
            ->querySingle("SELECT * FROM submenu WHERE (id = ? OR id = ? OR id = ?) AND ? = ANY (items)", [
                $menus[0]['submenu'],
                $menus[1]['submenu'],
                $menus[2]['submenu'],
                $this->data->id
            ]);
        if (!$this->_isResponseOK($submenu)) {
            $this->error("Произошла ошибка ненайденного элемента в базе данных!");
            return false;
        }
        $sub_ids = $this->_convertStringToArray($submenu['items']);
        $key_to_swap = 0;

        if ($this->data->move_direction == "left") {
            foreach ($sub_ids as $key => $sub_id) {
                if (($key == 0) && ($sub_id == $this->data->id)) {
                    $this->error("Нельзя передвинуть данный элемент влево так как он первый!");
                    return false;
                } elseif ($sub_id == $this->data->id) $key_to_swap = $key;
            }
            $tmp = $sub_ids[$key_to_swap - 1];
            $sub_ids[$key_to_swap - 1] = $sub_ids[$key_to_swap];
            $sub_ids[$key_to_swap] = $tmp;
        } elseif ($this->data->move_direction == "right") {
            foreach ($sub_ids as $key => $sub_id) {
                if (($key == count($sub_ids)) && ($sub_id == $this->data->id)) {
                    $this->error("Нельзя передвинуть данный элемент вправо так как он последний!");
                    return false;
                } elseif ($sub_id == $this->data->id) $key_to_swap = $key;
            }
            $tmp = $sub_ids[$key_to_swap + 1];
            $sub_ids[$key_to_swap + 1] = $sub_ids[$key_to_swap];
            $sub_ids[$key_to_swap] = $tmp;
        } else {
            $this->error("Неверный формат направления для передвигаемого элемента!");
            return false;
        }
        $submenu["items"] = '{' . implode(',', $sub_ids) . '}';
        $response = $this->Core->Database->where('id', $submenu['id'])->update("submenu", ["items" => $submenu['items']]);
        if (!$this->_isResponseOK($response)) {
            $this->error("Произошла ошибка при перемещении элемента в базе данных!");
            return false;
        } else return $response;
    }

    private function _ix_addDocument() {
        $data = [
            "date" => $this->data->date,
            "name" => $this->data->name,
            "author_id" => $this->decoded_token->id,
            "pull_site" => $this->data->pull_site,
            "description" => $this->data->description,
            "type" => $this->data->type,
            "is_important" => $this->data->is_important,
            "subdivision_id" => $this->data->subdivision_id,
            "is_active" => $this->data->is_active,
        ];

        $file = $this->_uploadFiles();
        if ($file == null) {
            $this->error("Прикрепите файл!");
            return false;
        } else $data['link'] = $file[0];

        $response = $this->Core->Database->insert('documents', $data);
        return ($this->_isResponseOK($response)) ? $data : false;
    }

    private function _ix_delDocument() {
        $link = $this->Core->Database->where("id", $this->data->id)->getOne("documents")['link'];

        if (file_exists(_SYSTEM_ROOT_FOLDER_ . $link) && ($link != "/uploads/files/default.jpg") && ($link != '/')) {
            if (unlink(_SYSTEM_ROOT_FOLDER_ . $link) == false) {
                $this->error("Проблемы с удалением файла на сервере!");
                return false;
            }
        }

        $response = $this->Core->Database->where("id", $this->data->id)->delete("documents");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editDocument() {
        $data = [
            "date" => $this->data->date,
            "name" => $this->data->name,
            "author_id" => $this->decoded_token->id,
            "pull_site" => $this->data->pull_site,
            "description" => $this->data->description,
            "type" => $this->data->type,
            "is_important" => $this->data->is_important,
            "subdivision_id" => $this->data->subdivision_id,
            "is_active" => $this->data->is_active,
        ];

        if ($this->data->fileIsChanged) {
            $old_link = $this->Core->Database->where("id", $this->data->id)->getOne("documents")['link'];

            if (file_exists(_SYSTEM_ROOT_FOLDER_ . $old_link) && ($old_link != "/uploads/files/default.jpg") && ($old_link != '/')) {
                if (unlink(_SYSTEM_ROOT_FOLDER_ . $old_link) == false) {
                    $this->error("Проблемы с удалением файла на сервере!");
                    return false;
                }
            }

            $file = $this->_uploadFiles();
            if ($file == null) {
                $this->error("Прикрепите файл!");
                return false;
            }

            $data['link'] = $file[0];
        }

        $response = $this->Core->Database->where("id", $this->data->id)->update('documents', $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getAllDocs() {
        $docs = $this->Core->Database->orderBy('date', 'DESC')->get('documents');
        return ["docs" => $docs];
    }

    private function _ix_getDocs() {
        $offset = $this->data->count * ($this->data->page - 1);

        $query_search = '%';
        if (!empty($this->data->query_search)) {
            $query_search .= $this->data->query_search . '%';
        }

        if (!empty($this->data->tag)) {
            if (!empty($this->data->date_before) && !empty($this->data->date_after)) {
                $count = $this->Core->Database
                    ->querySingle("SELECT count(*) FROM documents WHERE is_active = true AND type::int = (SELECT id FROM tags WHERE type = 2 AND name = ?) AND name ILIKE ? AND date >= ? AND date <= ?", [
                        $this->data->tag,
                        $query_search,
                        $this->data->date_after,
                        $this->data->date_before,
                    ])['count'];
                $response = $this->Core->Database
                    ->query("SELECT * FROM documents WHERE is_active = true AND type::int = (SELECT id FROM tags WHERE type = 2 AND name = ?) AND name ILIKE ? AND date >= ? AND date <= ? OFFSET ? LIMIT ?", [
                        $this->data->tag,
                        $query_search,
                        $this->data->date_after,
                        $this->data->date_before,
                        $offset,
                        $this->data->count
                    ]);
            } else {
                $count = $this->Core->Database
                    ->querySingle("SELECT count(*) FROM documents WHERE is_active = true AND type::int = (SELECT id FROM tags WHERE type = 2 AND name = ?) AND name ILIKE ?", [$this->data->tag, $query_search])['count'];
                $response = $this->Core->Database
                    ->query("SELECT * FROM documents WHERE is_active = true AND type::int = (SELECT id FROM tags WHERE type = 2 AND name = ?) AND name ILIKE ? OFFSET ? LIMIT ?", [$this->data->tag, $query_search, $offset, $this->data->count]);
            }
        } elseif ($this->data->subdivision_id != 0) {
            if (!empty($this->data->date_before) && !empty($this->data->date_after)) {
                $count = $this->Core->Database
                    ->where('subdivision_id', $this->data->subdivision_id)
                    ->where('name', $query_search, 'ILIKE')
                    ->where('date', $this->data->date_after, '>=')
                    ->where('date', $this->data->date_before, '<=')
                    ->where('is_active', true)
                    ->count('documents');
                $response = $this->Core->Database
                    ->where('subdivision_id', $this->data->subdivision_id)
                    ->where('name', $query_search, 'ILIKE')
                    ->where('date', $this->data->date_after, '>=')
                    ->where('date', $this->data->date_before, '<=')
                    ->orderBy('date', 'DESC')
                    ->where('is_active', true)
                    ->get('documents', [$offset, $this->data->count]);
            } else {
                $count = $this->Core->Database
                    ->where('subdivision_id', $this->data->subdivision_id)
                    ->where('name', $query_search, 'ILIKE')
                    ->where('is_active', true)
                    ->count('documents');
                $response = $this->Core->Database
                    ->where('subdivision_id', $this->data->subdivision_id)
                    ->where('name', $query_search, 'ILIKE')
                    ->where('is_active', true)
                    ->orderBy('date', 'DESC')
                    ->get('documents', [$offset, $this->data->count]);
            }
        } else {
            if (!empty($this->data->date_before) && !empty($this->data->date_after)) {
                if ($query_search != '%') {
                    $count = $this->Core->Database
                        ->where('name', $query_search, 'ILIKE')
                        ->where('date', $this->data->date_after, '>=')
                        ->where('date', $this->data->date_before, '<=')
                        ->where('is_active', true)
                        ->count('documents');
                    $response = $this->Core->Database
                        ->where('name', $query_search, 'ILIKE')
                        ->where('date', $this->data->date_after, '>=')
                        ->where('date', $this->data->date_before, '<=')
                        ->where('is_active', true)
                        ->orderBy('date', 'DESC')
                        ->get('documents', [$offset, $this->data->count]);
                } else {
                    $count = $this->Core->Database
                        ->orWhere('is_important', $this->data->is_important)
                        ->orWhere('pull_site', $this->data->pull_site)
                        ->where('date', $this->data->date_after, '>=')
                        ->where('date', $this->data->date_before, '<=')
                        ->where('is_active', true)
                        ->count('documents');
                    $response = $this->Core->Database
                        ->orWhere('is_important', $this->data->is_important)
                        ->orWhere('pull_site', $this->data->pull_site)
                        ->where('date', $this->data->date_after, '>=')
                        ->where('is_active', true)
                        ->where('date', $this->data->date_before, '<=')
                        ->orderBy('date', 'DESC')
                        ->get('documents', [$offset, $this->data->count]);
                }
            } else {
                if ($query_search != '%') {
                    $count = $this->Core->Database
                        ->where('name', $query_search, 'ILIKE')
                        ->where('is_active', true)
                        ->count('documents');
                    $response = $this->Core->Database
                        ->where('name', $query_search, 'ILIKE')
                        ->where('is_active', true)
                        ->orderBy('date', 'DESC')
                        ->get('documents', [$offset, $this->data->count]);
                } else {
                    $count = $this->Core->Database
                        ->where('is_important', $this->data->is_important)
                        ->where('is_active', true)
                        ->count('documents');
                    $response = $this->Core->Database
                        ->where('is_important', $this->data->is_important)
                        ->where('is_active', true)
                        ->orderBy('date', 'DESC')
                        ->get('documents', [$offset, $this->data->count]);
                }
            }
        }

        $count = ceil($count / $this->data->count);
        if ($count == 0) $count = 1;
        return ["docs" => $response, "count" => $count];
    }

    private function _convertStringToArray($string) {
        $string = str_replace("{", "", $string);
        $string = str_replace("}", "", $string);
        $array = explode(",", $string);
        if ($array[0] == "") unset($array[0]);
        return $array;
    }

    private function _getHeaderMenuLine($parent_id) {
        $root = $this->Core->Database->where('id', $parent_id)->getOne('menu_item');
        $root_submenu = $this->Core->Database->where('id', $root['submenu'])->getOne('submenu');
        $items_array = $this->_convertStringToArray($root_submenu['items']);
        $menu = array();
        if (!empty($items_array)) {
            foreach ($items_array as $id) {
                $item_menu = $this->Core->Database->where('id', $id)->getOne('menu_item');
                $item_subs = $this->Core->Database->where('id', $item_menu['submenu'])->getOne('submenu');
                $item_subs_id = $this->_convertStringToArray($item_subs['items']);
                foreach ($item_subs_id as $item_id) {
                    $menu_items = $this->Core->Database->where('id', $item_id)->getOne('menu_item');
                    $item_menu['subs'][] = $menu_items;
                }
                if ($item_menu !== null) $menu[] = $item_menu;
            }
        }
        return $menu;
    }

    private function _ix_getHeaderMenu() {
        $menu = array("up" => $this->_getHeaderMenuLine(1), "down" => $this->_getHeaderMenuLine(2));
        return $menu;
    }

    private function _ix_getFooterMenu() {
        $root = $this->Core->Database->where('id', 3)->getOne('menu_item');
        $root_submenu = $this->Core->Database->where('id', $root['submenu'])->getOne('submenu');
        $items_array = $this->_convertStringToArray($root_submenu['items']);
        $menu = array();
        if (!empty($items_array)) {
            foreach ($items_array as $id) {
                $item_menu = $this->Core->Database->where('id', $id)->getOne('menu_item');
                $item_subs = $this->Core->Database->where('id', $item_menu['submenu'])->getOne('submenu');
                $item_subs_id = $this->_convertStringToArray($item_subs['items']);
                foreach ($item_subs_id as $item_id) {
                    $menu_items = $this->Core->Database->where('id', $item_id)->getOne('menu_item');
                    $submenu_down = $this->Core->Database->where('id', $menu_items['submenu'])->getOne('submenu');
                    $submenu_down_id = $this->_convertStringToArray($submenu_down['items']);
                    foreach ($submenu_down_id as $subs_id) {
                        $subs = $this->Core->Database->where('id', $subs_id)->getOne('menu_item');
                        $menu_items['subs'][] = $subs;
                    }
                    $item_menu['subs'][] = $menu_items;
                }
                if ($item_menu !== null) $menu[] = $item_menu;
            }
        }
        return ["footer" => $menu];
    }

    private function _ix_addSubmenu() {
        $submenu = $this->Core->Database->insert('submenu', ["items" => "{}"], 'id');
        $data = ["name" => $this->data->title, "submenu" => $submenu];
        $menu_item = $this->Core->Database->insert('menu_item', $data, 'id');
        if ($menu_item === false || $menu_item == null || $menu_item == '') {
            $db_error = $this->Core->Database->getLastError();
            $this->error($db_error);

            return false;
        } else {
            $parent = $this->Core->Database->query("UPDATE submenu SET items = array_append(items, {$menu_item}::integer) WHERE id = {$this->data->parent_id}"
                ); # FIXME: корявый запрос в бд
            $response = array('id' => $menu_item, 'submenu' => $submenu);
            return $response;
        }
    }

    private function _ix_editItemMenu() {
        $item = $this->Core->Database->query("UPDATE menu_item SET name = '{$this->data->title}', link = '{$this->data->link}' WHERE id = {$this->data->id}"
            ); # FIXME: корявый запрос в бд
        return ($this->_isResponseOK($item)) ? $item : false;
    }

    private function _ix_editQueueItemMenu() {
        $parent = $this->Core->Database->where('id', $this->data->parent_id)->getOne('menu_item');

        $response = $this->Core->Database->query("UPDATE submenu SET items = '{" . implode(', ', $this->data->items) . "}' WHERE id = {$parent['submenu']}");

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_deleteSubmenu() {
        $item = $this->Core->Database->where('id', $this->data->id)->delete('menu_item');
        return ($this->_isResponseOK($item)) ? $item : false;
    }

    private function _ix_deleteItemMenu() {
        $submenu =
            $this->Core->Database->query("UPDATE submenu SET items = array_remove(items, {$this->data->id}::int) WHERE id = {$this->data->parent_id}"
            );
        $item = $this->Core->Database->where('id', $this->data->id)->delete('menu_item');
        return ($this->_isResponseOK($item)) ? $item : false;
    }

    private function _ix_addItemMenu() {
        $submenu = $this->Core->Database->insert('submenu', ["items" => "{}"], 'id');
        $data = ["name" => $this->data->title, "submenu" => $submenu];
        $menu_item = $this->Core->Database->insert('menu_item', $data, 'id');
        if ($this->_isResponseOK($menu_item)) {
            $parent = $this->Core->Database->query("UPDATE submenu SET items = array_append(items, {$menu_item}::integer) WHERE id = {$this->data->parent_id}"
                );
            $response = ['id' => $menu_item];
            return ['id' => $menu_item, "submenu" => $submenu];
        } else return false;
    }

    private function _editItemSubmenu($name, $id, $link) {
        $item = $this->Core->Database->query("UPDATE menu_item SET name = ?, link = ? WHERE id = {$id}", [$name, $link]);
        return ($this->_isResponseOK($item)) ? $item : false;
    }

    private function _ix_editItemSubmenu() {
        $response = null;
        foreach ($this->data->items as $item_menu) {
            $response = $this->_editItemSubmenu($item_menu->name, $item_menu->id, $item_menu->link);
        }
        $response = $this->_editItemSubmenu($this->data->title, $this->data->id, $this->data->link);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getMainTags() {
        $tags = $this->Core->Database->orderBy("id")->get('main_buttons');
        return $tags;
    }

    private function _ix_addMainTag() {
        $data = ["name" => $this->data->name, "link" => $this->data->link];
        $response = $this->Core->Database->insert('main_buttons', $data, 'id');
        return ($this->_isResponseOK($response)) ? ['id' => $response] : false;
    }

    private function _ix_editMainTag() {
        $response = $this->Core->Database
            ->where('id', $this->data->id)
            ->update('main_buttons', ["name" => $this->data->name, "link" => $this->data->link]);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delMainTag() {
        $response = $this->Core->Database->where('id', $this->data->id)->delete('main_buttons');
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getPartners() {
        $tags = $this->Core->Database->orderBy("id")->get('partners');
        return $tags;
    }

    private function _ix_addPartner() {
        $logo = $this->_uploadFiles();
        if ($logo == null) $logo = ["/uploads/files/default.jpg"];
        $data = ["name" => $this->data->name, "link" => $this->data->link, "logo" => $logo[0]];
        $response = $this->Core->Database->insert('partners', $data, 'id');
        return ($this->_isResponseOK($response)) ? ['id' => $response, 'logo' => $logo[0]] : false;
    }

    private function _ix_delPartner() {
        $response = $this->Core->Database->where('id', $this->data->id)->delete('partners');
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _genPass($len, $sp) {
        $pass = "";
        $ch = ['digit' => array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9),
               'lower' => array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's',
                                't', 'u', 'v', 'w', 'x', 'y', 'z'),
               'upper' => array('A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S',
                                'T', 'U', 'V', 'W', 'X', 'Y', 'Z')];

        if ($sp) {
            $ch['spec'] = array('!', '@', '#', '$', '%', '^', '&', '*', '_', '+');
        }
        $chTypes = array_keys($ch);
        $numTypes = count($chTypes) - 1;

        for ($i = 0; $i < $len; $i++) {
            $chType = $chTypes[mt_rand(0, $numTypes)];
            $pass .= $ch[$chType][mt_rand(0, count($ch[$chType]) - 1)];
        }

        return $pass;
    }

    private function translit($string) {
        $converter = array(
            'а' => 'a',   'б' => 'b',   'в' => 'v',
            'г' => 'g',   'д' => 'd',   'е' => 'e',
            'ё' => 'e',   'ж' => 'zh',  'з' => 'z',
            'и' => 'i',   'й' => 'y',   'к' => 'k',
            'л' => 'l',   'м' => 'm',   'н' => 'n',
            'о' => 'o',   'п' => 'p',   'р' => 'r',
            'с' => 's',   'т' => 't',   'у' => 'u',
            'ф' => 'f',   'х' => 'h',   'ц' => 'c',
            'ч' => 'ch',  'ш' => 'sh',  'щ' => 'sch',
            'ь' => '',    'ы' => 'y',   'ъ' => '',
            'э' => 'e',   'ю' => 'yu',  'я' => 'ya',

            'А' => 'A',   'Б' => 'B',   'В' => 'V',
            'Г' => 'G',   'Д' => 'D',   'Е' => 'E',
            'Ё' => 'E',   'Ж' => 'Zh',  'З' => 'Z',
            'И' => 'I',   'Й' => 'Y',   'К' => 'K',
            'Л' => 'L',   'М' => 'M',   'Н' => 'N',
            'О' => 'O',   'П' => 'P',   'Р' => 'R',
            'С' => 'S',   'Т' => 'T',   'У' => 'U',
            'Ф' => 'F',   'Х' => 'H',   'Ц' => 'C',
            'Ч' => 'Ch',  'Ш' => 'Sh',  'Щ' => 'Sch',
            'Ь' => '',    'Ы' => 'Y',   'Ъ' => '',
            'Э' => 'E',   'Ю' => 'Yu',  'Я' => 'Ya',
        );
        return strtr($string, $converter);
    }

    // todo: добавить проверку на наличие начальника и возвращать ошибку в случае его наличия
    private function _ix_addStuff() {
        $avatar = $this->_uploadFiles();
        if ($avatar == null) {
            $avatar = ["/uploads/files/default.jpg"];
        }

        $password = $this->_genPass(10, true);
        $database_pass = password_hash($password, PASSWORD_DEFAULT);

        if (empty($this->data->fullname)) {
            $this->error("ФИО не может быть пустым!");
            return false;
        }

        if (!preg_match('/^[А-ЯA-Z][а-яa-zА-ЯA-Z\-]{0,}\s[А-ЯA-Z][а-яa-zА-ЯA-Z\-]{1,}(\s[А-ЯA-Z][а-яa-zА-ЯA-Z\-]{1,})?$/u', $this->data->fullname)) {
            $this->error("ФИО имеет неправильный формат!");
            return false;
        }

        $login_vars = explode(' ',$this->translit($this->data->fullname));

        if (isset($login_vars[2])) {
            $login = $login_vars[0] . '.' . $login_vars[1][0] . '.' . $login_vars[2] . '_' . rand(1000, 9999);
        } else {
            $login = $login_vars[0] . '.' . $login_vars[1] . '_' . rand(1000,9999);
        }

        if (strlen($login) > 50) {
            $this->error("Логин пользователя превышает допустимое количество символов, свяжитесь с технической поддержкой.");
            return false;
        }

        $user_data = [
            "fullname" => $this->data->fullname,
            "phone" => $this->data->phone,
            "address" => $this->data->address,
            "avatar" => $avatar[0],
            "email" => $this->data->email,
            "password" => $database_pass,
            "login" => $login,
        ];

        $subdivisions_data = [];
        foreach($this->data->subdivisions as $subdivision) {
            if ($subdivision->important) {
                if (!$this->Core->Database->where("subdivision_id = {$subdivision->subdivision_id} and important = true")->has('subdivision_user')) {
                    $subdivision->important = true;
                } else {
                    $subdivision_name = $this->Core->Database->where('id', $subdivision->subdivision_id)->getOne('subdivisions', 'name')['name'];

                    $this->error('У подразделения ' . $subdivision_name . ' уже есть начальник.');
                    return false;
                }
            }

            $subdivisions_data[] = [
                "subdivision_id" => $subdivision->subdivision_id,
                "position" => $subdivision->position,
                "professional_worktime" => $subdivision->professional_worktime,
                "total_worktime" => $subdivision->total_worktime,
                "training" => $subdivision->training,
                "education" => $subdivision->education,
                "disciplines" => $subdivision->disciplines,
                "important" => $subdivision->important,
                "info" => $subdivision->info,
                "user_id" => 0,
            ];
        }

        $response = $this->Core->Database->insert('users', $user_data, 'id');

        if($this->_isResponseOK($response) != false) {
            $subdivision_response = true;

            foreach($subdivisions_data as $subdivision_data) {
                $subdivision_data['user_id'] = $response;

                $subdivision_response = $this->Core->Database->insert('subdivision_user', $subdivision_data);
            }
        }

        return ($this->_isResponseOK($response) && $this->_isResponseOK($subdivision_response)) ? ['id' => $response, 'login' => $login, 'password' => $password] : false;
    }

    private function _ix_getStuff() {
        $stuffs = $this->Core->Database->where('subdivision_id', $this->data->subdivision_id)->orderBy('id')->get('subdivision_user');

        foreach($stuffs as $stuff) {
            $tags[] = [
                'user' => $this->Core->Database->where('id', $stuff['user_id'])->getOne('users', 'id, email, phone, avatar, address, fullname'),
                'subdivisions' => [$stuff],
            ];
        }

        return $tags;
    }

    private function _ix_getAllStuffs() {
        $stuffs = $this->Core->Database->orderBy('id')->get('users', null, 'id, email, phone, avatar, address, fullname');

        foreach($stuffs as $stuff) {
            $tags[] = [
                'user' => $stuff,
                'subdivisions' => $this->Core->Database->where('user_id', $stuff['id'])->get('subdivision_user'),
            ];

        }

        return ["stuffs" => $tags];
    }

    private function _ix_editStuff() {
        if (isset($this->data->logo)) {
            if ($this->data->logo) {
                $data['avatar'] = $this->_uploadFiles()[0];
            }
        }

        if (isset($this->data->phone)) $data['phone'] = $this->data->phone;
        if (isset($this->data->email)) $data['email'] = $this->data->email;
        if (isset($this->data->fullname)) $data['fullname'] = $this->data->fullname;
        if (isset($this->data->address)) $data['address'] = $this->data->address;
        if (isset($this->data->subdivisions)) {
            $subdivisions_data = (array)$this->data->subdivisions;

            foreach($subdivisions_data as $subdivision) {
                if ($subdivision->important) {
                    $old_important = $this->Core->Database->where('user_id', $this->data->id)->where('subdivision_id', $subdivision->subdivision_id)->getOne('subdivision_user', 'important')['important'];

                    if ($old_important || !$this->Core->Database->where('subdivision_id', $subdivision->subdivision_id)->where('important = true')->has('subdivision_user')) {
                        $subdivision->important = true;
                    } else {
                        $subdivision_name = $this->Core->Database->where('id', $subdivision->subdivision_id)->getOne('subdivisions', 'name')['name'];

                        $this->error('У подразделения ' . $subdivision_name . ' уже есть начальник.');
                        return false;
                    }
                }
            }
        }

        $response = $this->Core->Database->where("id", $this->data->id)->update("users", $data);

        if ($this->_isResponseOK($response)) {
            if (isset($data['avatar'])) {
                $response = ['updated_link' => $data['avatar']];
            }

            $subdivisions_id = [];
            foreach($subdivisions_data as $subdivision) {
                $id_query = $this->Core->Database->where('user_id', $this->data->id)->where('subdivision_id', $subdivision->subdivision_id)->getOne('subdivision_user', 'id')['id'];

                if($id_query != false) {
                    $subdivision_response = $this->Core->Database->where('id', $id_query)->update('subdivision_user', (array)$subdivision);

                    $subdivisions_id[] = $id_query;
                }
                else if($id_query == false) {
                    $subdivision->user_id = $this->data->id;
                    $subdivision_response = $this->Core->Database->insert('subdivision_user', (array)$subdivision, 'id');

                    if($subdivision_response != false)
                        $subdivisions_id[] = $subdivision_response;
                }

                if($subdivision_response == false) {
                    $this->error(var_dump((array)$subdivision));
                    return false;
                }
            }

            if($subdivisions_id != []) {
                $subdivision_delete_response = $this->Core->Database->where('id', $subdivisions_id, '!=')->where('user_id', $this->data->id)->delete('subdivision_user');
            }

        } else $response = false;

        return $response;
    }

    private function _ix_delStuff() {
        $response = $this->Core->Database->where("id", $this->data->id)->delete("users");
        $subdivision_response = $this->Core->Database->where("user_id", $this->data->id)->delete("subdivision_user");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getSocial() {
        $response = $this->Core->Database->orderBy("id")->get('social');
        $result = array();
        foreach ($response as $item) {
            $result[$item['name']] = $item['link'];
        }
        return $result;
    }

    private function _ix_editSocial() {
        $data = array("vk" => $this->data->vk, "git" => $this->data->git, "twitter" => $this->data->twitter,
                      "youtube" => $this->data->youtube, "telegram" => $this->data->telegram, "facebook" => $this->data->facebook,
                      "instagram" => $this->data->instagram, "googlePlay" => $this->data->googlePlay,
                      "googlePlus" => $this->data->googlePlus, "appStore" => $this->data->appStore);
        $response = array();
        foreach ($data as $social => $link) {
            $response[] = $this->Core->Database
            ->where("name", $social)
            ->update("social", ["link" => $link]);
        }
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addStudyStream() {
        $data = ["course" => $this->data->course, "name" => $this->data->name, "study_state_id" => $this->data->study_state_id];
        $response = $this->Core->Database->insert("study_stream", $data, "id");
        return ($this->_isResponseOK($response)) ? ["id" => $response] : false;
    }


    private function _ix_addStudyState() {
        $data = ['name' => $this->data->name, 'study_type_id' => $this->data->study_type_id];
        $response = $this->Core->Database->insert('study_state', $data, 'id');

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editStudyType() {
        $data = ['name' => $this->data->name];
        $response = $this->Core->Database->where('id', $this->data->id)->update('study_type', $data);

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delStudyType() {
        $response = $this->Core->Database->where('id', $this->data->id)->delete('study_type');
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addStudyType() {
        $data = ['name' => $this->data->name];
        $response = $this->Core->Database->insert('study_type', $data, 'id');

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editStudyState() {
        $data = ['name' => $this->data->name];
        if(isset($this->date->study_type_id))$data['study_type_id'] = $this->data->study_type_id;
        $response = $this->Core->Database->where('id', $this->data->id)->update('study_state', $data);

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delStudyState() {
        $response = $this->Core->Database->where('id', $this->data->id)->delete('study_state');
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editStudyStream() {
        if(isset($this->data->name))$data['name'] = $this->data->name;
        if(isset($this->data->study_state_id))$data['study_state_id'] = $this->data->study_state_id;

        $response = $this->Core->Database->where("id", $this->data->id)->update("study_stream", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delStudyStream() {
        $response = $this->Core->Database->where("id", $this->data->id)->delete("study_stream");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addStudyForm() {
        $data = ["stage" => $this->data->stage, "form" => $this->data->form];

        $response = array();

        $response[] = $this->Core->Database->insert("study_forms", $data, "id");
        if ($this->_isResponseOK($response)) {
            $response[] = $this->Core->Database->query("UPDATE study_stream SET forms_id = array_append(forms_id, ?::integer) WHERE id = ?", [$response[0], $this->data->stream_id]);
            return ["id" => $response[0]];
        } else return false;
    }

    private function _ix_editStudyForm() {
        $data = ["stage" => $this->data->stage, "form" => $this->data->form];
        $response = $this->Core->Database->where("id", $this->data->id)->update("study_forms", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delStudyForm() {
        $response = array();
        $response[] = $this->Core->Database->where("id", $this->data->id)->delete("study_forms");
        if ($this->_isResponseOK($response)) {
            $response[] = $this->Core->Database->query("UPDATE study_stream SET forms_id = array_remove(forms_id, ?::integer) WHERE id = ?", [$this->data->id, $this->data->stream_id]);
            return $response;
        } else return false;
    }

    private function _ix_addStudyGroup() {
        $data = ["name" => $this->data->name];
        $response = array();
        $response[] = $this->Core->Database->insert("study_groups", $data, "id");
        if ($this->_isResponseOK($response)) {
            $response[] = $this->Core->Database->query("UPDATE study_forms SET groups_id = array_append(groups_id, ?::integer) WHERE id = ?", [$response[0], $this->data->form_id]);
            return ["id" => $response[0]];
        } else return false;
    }

    private function _ix_editStudyGroup() {
        $file = $this->_uploadFiles();
        if ($file == null) {
            $this->error("Прикрепите файл!");
            return false;
        }

        $data = ["file" => $file[0]];

        $response = $this->Core->Database->where("id", $this->data->id)->update("study_groups", $data);
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delStudyGroup() {
        $response = array();
        $response[] = $this->Core->Database->where("id", $this->data->id)->delete("study_groups");
        if ($this->_isResponseOK($response)) {
            $response[] = $this->Core->Database->query("UPDATE study_forms SET groups_id = array_remove(groups_id, ?::integer) WHERE id = ?", [$this->data->id, $this->data->form_id]);
            return $response;
        } else return false;
    }

    private function _ix_getStudyTimetable() {
        $study_types = $this->Core->Database->get('study_type');

        $result = [];
        foreach($study_types as $study_type) {
            $study_states = $this->Core->Database->where('study_type_id', $study_type['id'])->get('study_state');

            $tmp = [];
            foreach($study_states as $study_state) {
                $timetable = ["courses" => array()];
                for ($i = 0; $i < 6; $i++) {
                    $timetable["courses"][] = $this->Core->Database->where('course', $i)->where('study_state_id', $study_state['id'])->orderBy("id")->get('study_stream');
                    foreach ($timetable["courses"][$i] as &$stream) {
                        $forms_array = $this->_convertStringToArray($stream["forms_id"]);
                        unset($stream["forms_id"]);
                        $stream['forms'] = array();
                        $stream_counter = 0;
                        foreach ($forms_array as $form_id) {
                            $stream['forms'][] = $this->Core->Database->where('id', $form_id)->getOne('study_forms');
                            $groups_array = $this->_convertStringToArray($stream['forms'][$stream_counter]["groups_id"]);
                            unset($stream['forms'][$stream_counter]["groups_id"]);
                            $stream['forms'][$stream_counter]['groups'] = array();
                            foreach ($groups_array as $group_id) {
                                $stream['forms'][$stream_counter]['groups'][] =
                                    $this->Core->Database->where('id', $group_id)->getOne('study_groups');
                            }
                            $stream_counter++;
                        }
                    }
                }

                $tmp[] = [
                    'study_state' => $study_state,
                    'timetable' => $timetable,
                ];
            }

            $result[] = [
                'study_type' => $study_type,
                'study_state_timetable' => $tmp,
            ];
        }

        return $result;
    }

    private function _ix_addJSONTable() {
        $json = (empty($this->data->json)) ? "{}" : $this->data->json;
        $id = $this->Core->Database->insert("tables_view", ["name" => $this->data->name, "json" => $json], "id");
        return ["created_id" => $id];
    }

    private function _ix_editJSONTable() {
        if (!empty($this->data->json)) $data['json'] = $this->data->json;
        if (!empty($this->data->name)) $data['name'] = $this->data->name;
        if (empty($data)) {
            $this->error("Данные для обновления не могут быть пустыми!");
            return false;
        }
        $response = $this->Core->Database->where("id", $this->data->id)->update("tables_view", $data);
        $response = ($this->_isResponseOK($response)) ? true : false;
        return $response;
    }

    private function _ix_getJSONTable() {
        if (!empty($this->data->show_all) && ($this->data->show_all == true)) {
            $response = ["json_tables" => $this->Core->Database->get("tables_view", null, "id, name")];
        } else {
            $response = ["json_table" => $this->Core->Database->where("id", $this->data->id)->getOne("tables_view")];
        }
        return $response;
    }

    private function _ix_delJSONTable() {
        $response = $this->Core->Database->where("id", $this->data->id)->delete("tables_view");
        if ($this->_isResponseOK($response)) {
            $response = true;
        } else {
            $response = false;
            $this->error("Данная таблица была уже удалена...");
        }
        return $response;
    }

    private function _addSlider($name) {
        $response = $this->Core->Database->insert("sliders", ["name" => $name, "slides" => "{}"], "id");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _editTemplateCategoryPageRelation($pages_id, $menu_item_id) {
        $response = $this->Core->Database->where("menu_item_id", $menu_item_id)->update("pages", ["menu_item_id" => null]);

        foreach($pages_id as $id) {
            $response = $this->Core->Database->where("id", $id)->update("pages", ["menu_item_id" => $menu_item_id]);
        }

        return $response;
    }

    private function _ix_addTemplateCategoryPage() {
        return $this->_addTemplateCategoryPage((array)$this->data);
    }

    private function _addTemplateCategoryPage($data) {
        $page_id = $this->Core->Database->insert("pages", $data, "id");
        $response = $this->Core->Database->where('id', $page_id)->getOne('pages');
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editTemplateCategoryMenuItem() {
        $data = [
            "parent_id" => $this->data->parent_id,
            "name" => $this->data->name,
            "submenu" => $this->data->submenu_id,
            "link" => $this->data->link,
            "is_page" => $this->data->is_page,
            "modals" => $this->data->modals,
        ];
        $response = $this->Core->Database->where("id", $this->data->id)->update("template_category_menu_item", $data);

        if(isset($this->data->pages)) {
            $pages_id = [];
            foreach($this->data->pages as $page) {
                $pages_id[] = $page->id;
            }

            if($pages_id == [])
                $pages_response = $this->Core->Database->where('menu_item_id', $this->data->id)->delete('pages');
            else
                $pages_response = $this->Core->Database->where('id', $pages_id, '!=')->where('menu_item_id', $this->data->id)->delete('pages');
        }

        return ($this->_isResponseOK($response)) ? ["response" => $response] : false;
    }

    private function _ix_addTemplateCategoryMenu() {
        $data = [
            "parent_id" => $this->data->parent_id,
            "name" => $this->data->name,
            "submenu" => $this->data->submenu_id,
            "link" => $this->data->link,
            "is_page" => $this->data->is_page,
            "modals" => "{}",
        ];

        $menu_item = $this->Core->Database->insert('template_category_menu_item', $data, 'id');
        if ($this->_isResponseOK($menu_item)) {
            if($this->data->parent_id == 0) {
                $parent = $this->Core->Database->query("UPDATE template_category_submenu SET items = array_append(items, ?::integer) WHERE id = ?", [$menu_item, $this->data->submenu_id]);
            }
            return ["id" => $menu_item];
        } else return false;
    }

    private function _addTemplateCategoryMenu($name) {
        $submenu = $this->Core->Database->insert('template_category_submenu', ["items" => "{}"], 'id');
        $data = ["name" => $name, "submenu" => $submenu];
        $menu_item = $this->Core->Database->insert('template_category_menu_item', $data, 'id');
        return ($this->_isResponseOK($menu_item)) ? ["menu_id" => $menu_item, "submenu_id" => $submenu] : false;
    }

    private function _ix_addTemplateCategory() {
        $slider_id = 0;

        if ($this->data->is_slider) {
            $slider_id = $this->_addSlider($this->data->name);
        }

        $menu = $this->_addTemplateCategoryMenu($this->data->name);

        $data = [
            "name" => $this->data->name,
            "slider_id" => $slider_id,
            "menu_id" => $menu['menu_id'],
            "submenu_id" => $menu['submenu_id'],
            "subdivision_id" => $this->data->subdivision_id
        ];

        $response = $this->Core->Database->insert("template_page", $data, "id");
        return ($this->_isResponseOK($response)) ? [
                "id" => $response,
                "slider_id" => $slider_id,
                "menu_id" => $menu['menu_id'],
                "submenu_id" => $menu['submenu_id']
        ] : false;
    }

    private function _ix_delTemplateCategory() {
        $template_page = $this->Core->Database->where('id', $this->data->id)->getOne('template_page');

        $slider = $this->Core->Database->where('id', $template_page['slider_id'])->getOne('sliders');
        $slides = $this->_convertStringToArray($slider['slides']);
        foreach ($slides as $slide) {
            $response = $this->Core->Database->where('id', $slide['id'])->delete('slides');
            if ($this->_isResponseOK($response) == false) {
                $this->error("Ошибка при удалении слайдера категории");
                return false;
            }
        }

        $root_submenu = $this->Core->Database->where('id', $template_page['submenu_id'])->getOne('template_category_submenu');
        $items_array = $this->_convertStringToArray($root_submenu['items']);
        foreach ($items_array as $id) {
            if($this->Core->Database->where("id", $id)->has("template_category_menu_item")) {
                $response = $this->Core->Database->where('id', $id)->delete('template_category_menu_item');
                if ($this->_isResponseOK($response) == false) {
                    $this->error("Ошибка при удалении пунктов меню категории");
                    return false;
                }
            }

            if($this->Core->Database->where('menu_item_id', $id)->has('pages')) {
                $response = $this->Core->Database->where('menu_item_id', $id)->delete('pages');
                if ($this->_isResponseOK($response) == false) {
                    $this->error("Ошибка при удалении страниц категории");
                    return false;
                }
            }
            $submenus_item_menu = $this->Core->Database->where('parent_id', $id)->get('template_category_menu_item');
            foreach($submenus_item_menu as $submenu_item_menu) {
                if($this->Core->Database->where('menu_item_id', $submenu_item_menu['id'])->has('pages')) {
                    $response = $this->Core->Database->where('menu_item_id', $submenu_item_menu['id'])->delete('pages');
                    if ($this->_isResponseOK($response) == false) {
                        $this->error("Ошибка при удалении страниц категории");
                        return false;
                    }
                }

                if($this->Core->Database->where('id', $submenu_item_menu['id'])->has('template_category_menu_item')) {
                    $response = $this->Core->Database->where('id', $submenu_item_menu['id'])->delete('template_category_menu_item');
                    if ($this->_isResponseOK($response) == false) {
                        $this->error("Ошибка при удалении пунктов меню категории");
                        return false;
                    }
                }
            }
        }
        if($this->Core->Database->where('id', $template_page['submenu_id'])->has('template_category_submenu')) {
            $response = $this->Core->Database->where('id', $template_page['submenu_id'])->delete('template_category_submenu');
            if ($this->_isResponseOK($response) == false) {
                $this->error("Ошибка при удалении пунктов меню категории");
                return false;
            }
        }
        if($this->Core->Database->where('id', $template_page['id'])->has('template_page')) {
            $response = $this->Core->Database->where('id', $template_page['id'])->delete('template_page');
            if ($this->_isResponseOK($response) == false) {
                $this->error("Ошибка при удалении категории");
                return false;
            }
        }

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editTemplateCategory() {
        $slider_id = 0;

        if ($this->data->is_slider && $this->data->slider_id !== 0) {
            $slider_id = $this->data->slider_id;
        } elseif ($this->data->is_slider && $this->data->slider_id == 0) {
            $slider_id = $this->_addSlider($this->data->name);
        }

        $data = [
            "name" => $this->data->name,
            "slider_id" => $slider_id,
            "subdivision_id" => $this->data->subdivision_id
        ];

        $response = $this->Core->Database->where("id", $this->data->id)->update("template_page", $data);
        return ($this->_isResponseOK($response)) ? ["response" => $response, "slider_id" => $slider_id] : false;
    }

    private function _ix_addTemplateCategoryModal() {
        $data = ["name" => $this->data->name];
        $response = $this->Core->Database->insert("pages", $data, "id");
        // return ($this->_isResponseOK($response)) ? ["response" => $response] : false;
        if ($this->_isResponseOK($response)) {
            $parent = $this->Core->Database->query("UPDATE template_category_menu_item SET modals = array_append(modals, ?::integer) WHERE id = ?", [$response, $this->data->menu_id]);
            return $response;
            # TODO: интересный момент
        } else return false;
    }

    private function _ix_delTemplateCategoryModal() {
        $response = $this->Core->Database->where("id", $this->data->menu_id)->has("template_category_menu_item");
        if ($this->_isResponseOK($response)) {
            $response = $this->Core->Database->where("id", $this->data->id)->delete("pages");
            if ($this->_isResponseOK($response)) {
                $response = $this->Core->Database->query("UPDATE template_category_menu_item SET modals = array_remove(modals, ?::integer) WHERE id = ?", [$this->data->id,$this->data->menu_id]);
                return ($this->_isResponseOK($response)) ? $response : false;
            } else return false;
        } else return false;
    }

    private function _ix_delTemplateCategoryMenu() {
        $response = $this->Core->Database->where("id", $this->data->id)->getOne("template_category_menu_item","submenu,modals");
        $pages = $this->Core->Database->where("menu_item_id", $this->data->id)->get("pages", null, "id");
	    if ($this->_isResponseOK($response) == false) {
            $this->error("template_category_menu_item with id-{$this->data->id} already deleted!");
            return false;
        }
        $modals_ids =  $this->_convertStringToArray($response["modals"]);
        $submenu_id = $response["submenu"];

        foreach ($modals_ids as $modal_id) {
            $response = $this->Core->Database->where("id", $modal_id)->delete("pages");
            if ($this->_isResponseOK($response) == false) return false;
        }

        foreach($pages as $page) {
            $response = $this->Core->Database->where("id", $page['id'])->delete("pages");
            if ($this->_isResponseOK($response) == false) return false;
        }

        if (($submenu_id != 0) || (!empty($submenu_id))) {
            $response = $this->Core->Database->query("UPDATE template_category_submenu SET items = array_remove(items, ?::integer) WHERE id = ?", [$this->data->id, $submenu_id]);
            if ($this->_isResponseOK($response) == false) return false;
        }

        $response = $this->Core->Database->where("id", $this->data->id)->delete("template_category_menu_item");
        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editQueueTemplateCategoryMenu() {
        $parent = $this->Core->Database->where('id', $this->data->submenu_id)->getOne('template_category_submenu');

        $response = $this->Core->Database->query("UPDATE template_category_submenu SET items = '{" . implode(', ', $this->data->items) . "}' WHERE id = {$parent['id']}");

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _getTemplateCategoryModal($id) {
        $response = $this->Core->Database->where("id", $id)->getOne("pages");
        if ($this->_isResponseOK($response)) {
            unset($response["is_html"]);
            unset($response["text"]);
            return $response;
        } else return false;
    }

    private function _ix_getTemplateCategory($id = null) {
        if ($id !== null)
            $response = $this->Core->Database->where("id", $id)->getOne("template_page");
        else
            $response = $this->Core->Database->where("id", $this->data->id)->getOne("template_page");
        if ($response === false || $response == null || $response == '') {
            $db_error = $this->Core->Database->getLastError();
            $this->error($db_error);

            return false;
        } else {
            $response["slides"] = $this->_ix_getSlider($response['slider_id'])["slides"];

            $root_submenu = $this->Core->Database->where('id', $response['submenu_id'])->getOne('template_category_submenu');
            $items_array = $this->_convertStringToArray($root_submenu['items']);
            $menu = array();
            if (!empty($items_array)) {
                foreach ($items_array as $id) {
                    $item_menu = $this->Core->Database->where('id', $id)->getOne('template_category_menu_item');
                    if($item_menu == false) {
                        continue;
                    }
                    $item_menu['pages'] = $this->Core->Database->where('menu_item_id', $item_menu['id'])->get('pages', null, 'id, name');
                    $modals_array = $this->_convertStringToArray($item_menu["modals"]);
                    unset($item_menu["modals"]);
                    unset($item_menu["submenu"]);
                    $item_menu["modals"] = array();
                    foreach ($modals_array as $modal_id)
                        $item_menu["modals"][] = $this->_getTemplateCategoryModal($modal_id);

                    $result_submenus_item_menu = [];
                    $submenus_item_menu = $this->Core->Database->where('parent_id', $item_menu['id'])->get('template_category_menu_item');
                    foreach($submenus_item_menu as $submenu_item_menu) {
                        $submenu_item_menu['pages'] = $this->Core->Database->where('menu_item_id', $submenu_item_menu['id'])->get('pages', null, 'id, name');
                        $submenu_modals_array = $this->_convertStringToArray($submenu_item_menu["modals"]);
                        unset($submenu_item_menu["modals"]);
                        unset($submenu_item_menu["submenu"]);
                        $submenu_item_menu["modals"] = array();
                        foreach ($submenu_modals_array as $submenu_modal_id)
                            $submenu_item_menu["modals"][] = $this->_getTemplateCategoryModal($submenu_modal_id);

                        $result_submenus_item_menu[] = $submenu_item_menu;
                    }

                    $item_menu['submenus_item_menu'] = $result_submenus_item_menu;

                    if ($item_menu !== null)
                        $menu[] = $item_menu;
                }
            }

            $response["menu"] = $menu;
            return $response;
        }
    }

    private function _ix_getTemplateCategories() {
        $objects = $this->Core->Database->orderBy("id")->get("template_page", 1000, "id");
        $result = array();
        foreach ($objects as $object) {
            $result[] = $this->_ix_getTemplateCategory($object["id"]);
        }
        return $result;
    }

    private function _ix_getTemplateCategoryPage() {
        $response = $this->Core->Database->where("id", $this->data->id)->getOne("pages");

        if($this->_isResponseOK($response)) {
            $attachments_array = $this->_convertStringToArray($response["attachments"]);

            unset($response["attachments"]);
            unset($response["is_html"]);
            unset($response["text"]);

            $response["attachments"] = array();

            foreach($attachments_array as $attachment) {
                $response["attachments"][] = $this->Core->Database
                    ->where("id", $attachment)
                    ->getOne("attachments");
            }

            $response["delta"] = json_decode($response["delta"], true);

            return $response;
        } else
            return false;
    }

    private function _ix_editTemplateCategoryPage() {
        if(isset($this->data->name))$data['name'] = $this->data->name;
        if(isset($this->data->text))$data['text'] = $this->data->text;
        if(isset($this->data->is_html))$data['is_html'] = $this->data->is_html;
        if(isset($this->data->attachments))$data['attachments'] = $this->data->attachments;
        if(isset($this->data->delta)) {
            $data['delta'] = $this->data->delta;

            $filter = $this->_checkHtml(json_decode($data['delta'])->html);
            if($filter !== true) {
                return $filter;
            }
        }
        $response = $this->Core->Database->where("id", $this->data->id)->update("pages", $data);

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delTemplateCategoryPage() {
        $response = $this->Core->Database->where('id', $this->data->id)->delete('pages');

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_addAttachment() {
        $files = $this->_uploadFiles();
        $link = "";
        if ($files == null) $this->error("Прикрепите файл!");
        if (is_array($files)) $link = $files[0];

        $data = [
            "name" => $this->data->name,
            "type" => $this->data->type,
            "description" => $this->data->description,
            "link" => $link
        ];

        $response = $this->Core->Database->insert("attachments", $data, "id");
        if ($this->_isResponseOK($response)) {
            $parent = $this->Core->Database->query("UPDATE pages SET attachments = array_append(attachments, ?::integer) WHERE id = ?", [$response, $this->data->page_id]);
            return ["id" => $response, "link" => $link];
        } else return false;
    }

    private function _ix_delAttachment() {
        $response = $this->Core->Database->where("id", $this->data->id)->delete("attachments");
        if ($this->_isResponseOK($response)) {
            $parent = $this->Core->Database->query("UPDATE pages SET attachments = array_remove(attachments, ?::integer) WHERE id = ?", [$this->data->id, $this->data->page_id]);
            return $response;
        } else return false;
    }

    private function _ix_addCustomUrl() {
        $data = [
            'raw_url' => $this->data->raw_url,
            'redirect' => preg_replace("/\/*$/", "", $this->data->redirect),
            'name' => $this->data->name,
        ];
        $response = $this->Core->Database->insert('custom_urls', $data, 'id');

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_delCustomUrl() {
        $response = $this->Core->Database->where('id', $this->data->id)->delete('custom_urls');

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_editCustomUrl() {
        if(isset($this->data->raw_url))$data['raw_url'] = $this->data->raw_url;
        if(isset($this->data->redirect))$data['redirect'] = preg_replace("/\/*$/", "", $this->data->redirect);
        if(isset($this->data->name))$data['name'] = $this->data->name;
        $response = $this->Core->Database->where('id', $this->data->id)->update('custom_urls', $data);

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_getAllCustomUrl() {
        return $this->Core->Database->get('custom_urls');
    }

    private function _ix_getCustomUrl() {
         return $this->Core->Database->where('redirect', preg_replace("/\/*$/", "", $this->data->redirect))->get('custom_urls');
    }

    private function _ix_getMetric() {
        $yandex_date = 31;
        if(isset($this->data->yandex_date)) {
            $yandex_date = $this->data->yandex_date;
        }

        $app_id = "53337976";

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api-metrika.yandex.net/stat/v1/data/bytime?id=" . $app_id . "&metrics=ym:s:users&group=day&date1=" . $yandex_date . "daysAgo&date2=today",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
                'Authorization: Bearer AQAAAAADwZ5DAAWhWpdIrV9ObklFjEOBdDJP7wo',
            ),
        ));

        $yandex_response = curl_exec($curl);
        $yandex_err = curl_error($curl);

        curl_close($curl);

        if ($yandex_err) {
            $response = [
                'data' => [
                    'yandex' => "cURL Error #:" . $yandex_err,
                ],
            ];
        } else {
            $response = [
                'data' => [
                    'yandex' => json_decode($yandex_response),
                ],
            ];
        }

        return ($this->_isResponseOK($response)) ? $response : false;
    }

    private function _ix_sendMail() {
//        $to  = "<ciu@stankin.ru>";
        $to  = "<minaev142@gmail.com>";

        $subject = "Обратная связь";

        $message = ' <p>Поступил запрос на обратную связь от ' . $this->data->name . '</p> </br></br> <p>' . $this->data->comment . '</p>';

        $headers  = "Content-type: text/html; charset=utf-8 \r\n";
        $headers .= "From: <" . $this->data->email . ">\r\n";

        return mail($to, $subject, $message, $headers);
    }

    private function _ix_addSubscribe() {
        $data = [
            'email' => $this->data->email,
            'name' => $this->data->name,
        ];
        $response = $this->Core->Database->insert('subscribers', $data, 'id');

        return ($this->_isResponseOK($response)) ? $response : false;
    }
}
