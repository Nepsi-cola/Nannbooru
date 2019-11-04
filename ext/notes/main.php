<?php

class Notes extends Extension
{
    public function onDatabaseUpgrade(DatabaseUpgradeEvent $event)
    {
        global $config, $database;

        // shortcut to latest
        if ($this->get_version("ext_notes_version") < 1) {
            $database->Execute("ALTER TABLE images ADD COLUMN notes INTEGER NOT NULL DEFAULT 0");
            $database->create_table("notes", "
					id SCORE_AIPK,
					enable INTEGER NOT NULL,
					image_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					user_ip CHAR(15) NOT NULL,
					date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					x1 INTEGER NOT NULL,
					y1 INTEGER NOT NULL,
					height INTEGER NOT NULL,
					width INTEGER NOT NULL,
					note TEXT NOT NULL,
					FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
					FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
					");
            $database->execute("CREATE INDEX notes_image_id_idx ON notes(image_id)", []);

            $database->create_table("note_request", "
					id SCORE_AIPK,
					image_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
					FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
					");
            $database->execute("CREATE INDEX note_request_image_id_idx ON note_request(image_id)", []);

            $database->create_table("note_histories", "
					id SCORE_AIPK,
					note_enable INTEGER NOT NULL,
					note_id INTEGER NOT NULL,
					review_id INTEGER NOT NULL,
					image_id INTEGER NOT NULL,
					user_id INTEGER NOT NULL,
					user_ip CHAR(15) NOT NULL,
					date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					x1 INTEGER NOT NULL,
					y1 INTEGER NOT NULL,
					height INTEGER NOT NULL,
					width INTEGER NOT NULL,
					note TEXT NOT NULL,
					FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
					FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE
					");
            $database->execute("CREATE INDEX note_histories_image_id_idx ON note_histories(image_id)", []);

            $config->set_int("notesNotesPerPage", 20);
            $config->set_int("notesRequestsPerPage", 20);
            $config->set_int("notesHistoriesPerPage", 20);

            $this->set_version("ext_notes_version", 1);
        }
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $page, $user;
        if ($event->page_matches("note")) {
            switch ($event->get_arg(0)) {
                case "list": //index
                    $this->get_notes_list($event); // This should show images like post/list but i don't know how do that.
                    break;
                case "requests": // The same as post/list but only for note_request table.
                    $this->get_notes_requests($event); // This should show images like post/list but i don't know how do that.
                    break;
                case "search":
                    if (!$user->is_anonymous()) {
                        $this->theme->search_notes_page($page);
                    }
                    break;
                case "updated": //Thinking how to build this function.
                    $this->get_histories($event);
                    break;
                case "history": //Thinking how to build this function.
                    $this->get_history($event);
                    break;
                case "revert":
                    $noteID   = $event->get_arg(1);
                    $reviewID = $event->get_arg(2);
                    if (!$user->is_anonymous()) {
                        $this->revert_history($noteID, $reviewID);
                    }

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link("note/updated"));
                    break;
                case "add_note":
                    if (!$user->is_anonymous()) {
                        $this->add_new_note();
                    }

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link("post/view/".$_POST["image_id"]));
                    break;
                case "add_request":
                    if (!$user->is_anonymous()) {
                        $this->add_note_request();
                    }

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link("post/view/".$_POST["image_id"]));
                    break;
                case "nuke_notes":
                    if ($user->can(Permissions::NOTES_ADMIN)) {
                        $this->nuke_notes();
                    }

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link("post/view/".$_POST["image_id"]));
                    break;
                case "nuke_requests":
                    if ($user->can(Permissions::NOTES_ADMIN)) {
                        $this->nuke_requests();
                    }

                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link("post/view/".$_POST["image_id"]));
                    break;
                case "edit_note":
                    if (!$user->is_anonymous()) {
                        $this->update_note();
                        $page->set_mode(PageMode::REDIRECT);
                        $page->set_redirect(make_link("post/view/" . $_POST["image_id"]));
                    }
                    break;
                case "delete_note":
                    if ($user->can(Permissions::NOTES_ADMIN)) {
                        $this->delete_note();
                        $page->set_mode(PageMode::REDIRECT);
                        $page->set_redirect(make_link("post/view/".$_POST["image_id"]));
                    }
                    break;
                default:
                    $page->set_mode(PageMode::REDIRECT);
                    $page->set_redirect(make_link("note/list"));
                    break;
            }
        }
    }


    /*
     * HERE WE LOAD THE NOTES IN THE IMAGE
     */
    public function onDisplayingImage(DisplayingImageEvent $event)
    {
        global $page, $user;

        //display form on image event
        $notes = $this->get_notes($event->image->id);
        $this->theme->display_note_system($page, $event->image->id, $notes, $user->can(Permissions::NOTES_ADMIN));
    }


    /*
     * HERE WE ADD THE BUTTONS ON SIDEBAR
     */
    public function onImageAdminBlockBuilding(ImageAdminBlockBuildingEvent $event)
    {
        global $user;
        if (!$user->is_anonymous()) {
            $event->add_part($this->theme->note_button($event->image->id));
            $event->add_part($this->theme->request_button($event->image->id));
            if ($user->can(Permissions::NOTES_ADMIN)) {
                $event->add_part($this->theme->nuke_notes_button($event->image->id));
                $event->add_part($this->theme->nuke_requests_button($event->image->id));
            }
        }
    }


    /*
     * HERE WE ADD QUERYLETS TO ADD SEARCH SYSTEM
     */
    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        $matches = [];
        if (preg_match("/^note[=|:](.*)$/i", $event->term, $matches)) {
            $notes = int_escape($matches[1]);
            $event->add_querylet(new Querylet("images.id IN (SELECT image_id FROM notes WHERE note = $notes)"));
        } elseif (preg_match("/^notes([:]?<|[:]?>|[:]?<=|[:]?>=|[:|=])(\d+)%/i", $event->term, $matches)) {
            $cmp = ltrim($matches[1], ":") ?: "=";
            $notes = $matches[2];
            $event->add_querylet(new Querylet("images.id IN (SELECT id FROM images WHERE notes $cmp $notes)"));
        } elseif (preg_match("/^notes_by[=|:](.*)$/i", $event->term, $matches)) {
            $user = User::by_name($matches[1]);
            if (!is_null($user)) {
                $user_id = $user->id;
            } else {
                $user_id = -1;
            }

            $event->add_querylet(new Querylet("images.id IN (SELECT image_id FROM notes WHERE user_id = $user_id)"));
        } elseif (preg_match("/^(notes_by_userno|notes_by_user_id)[=|:](\d+)$/i", $event->term, $matches)) {
            $user_id = int_escape($matches[2]);
            $event->add_querylet(new Querylet("images.id IN (SELECT image_id FROM notes WHERE user_id = $user_id)"));
        }
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        if ($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Notes";
            $block->body = $this->theme->get_help_html();
            $event->add_block($block);
        }
    }


    /**
     * HERE WE GET ALL NOTES FOR DISPLAYED IMAGE.
     */
    private function get_notes(int $imageID): array
    {
        global $database;

        return $database->get_all(
            "SELECT * ".
            "FROM notes ".
            "WHERE enable = ? AND image_id = ? ".
            "ORDER BY date ASC",
            ['1', $imageID]
        );
    }


    /*
     * HERE WE ADD A NOTE TO DATABASE
     */
    private function add_new_note()
    {
        global $database, $user;

        $imageID    = int_escape($_POST["image_id"]);
        $user_id    = $user->id;
        $noteX1     = int_escape($_POST["note_x1"]);
        $noteY1     = int_escape($_POST["note_y1"]);
        $noteHeight = int_escape($_POST["note_height"]);
        $noteWidth  = int_escape($_POST["note_width"]);
        $noteText   = html_escape($_POST["note_text"]);

        $database->execute(
            "
				INSERT INTO notes (enable, image_id, user_id, user_ip, date, x1, y1, height, width, note)
				VALUES (?, ?, ?, ?, now(), ?, ?, ?, ?, ?)",
            [1, $imageID, $user_id, $_SERVER['REMOTE_ADDR'], $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText]
        );

        $noteID = $database->get_last_insert_id('notes_id_seq');

        log_info("notes", "Note added {$noteID} by {$user->name}");

        $database->execute("UPDATE images SET notes=(SELECT COUNT(*) FROM notes WHERE image_id=?) WHERE id=?", [$imageID, $imageID]);

        $this->add_history(1, $noteID, $imageID, $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText);
    }



    /*
     * HERE WE ADD A REQUEST TO DATABASE
     */
    private function add_note_request()
    {
        global $database, $user;

        $image_id = int_escape($_POST["image_id"]);
        $user_id = $user->id;

        $database->execute(
            "
				INSERT INTO note_request (image_id, user_id, date)
				VALUES (?, ?, now())",
            [$image_id, $user_id]
        );

        $resultID = $database->get_last_insert_id('note_request_id_seq');

        log_info("notes", "Note requested {$resultID} by {$user->name}");
    }



    /*
    * HERE WE EDIT THE NOTE
    */
    private function update_note()
    {
        global $database;

        $note = [
            "noteX1"     => int_escape($_POST["note_x1"]),
            "noteY1"     => int_escape($_POST["note_y1"]),
            "noteHeight" => int_escape($_POST["note_height"]),
            "noteWidth"  => int_escape($_POST["note_width"]),
            "noteText"   => sql_escape(html_escape($_POST["note_text"])),
            "imageID"    => int_escape($_POST["image_id"]),
            "noteID"     => int_escape($_POST["note_id"])
        ];

        // validate parameters
        if (array_search(null, $note)|| strlen($note['noteText']) == 0) {
            return;
        }

        $database->execute("UPDATE notes ".
                           "SET x1 = ?, ".
                           "y1 = ?, ".
                           "height = ?, ".
                           "width = ?,".
                           "note = ? ".
                           "WHERE image_id = ? AND id = ?", array_values($note));

        $this->add_history(1, $note['noteID'], $note['imageID'], $note['noteX1'], $note['noteY1'], $note['noteHeight'], $note['noteWidth'], $note['noteText']);
    }


    /*
    * HERE WE DELETE THE NOTE
    */
    private function delete_note()
    {
        global $user, $database;

        $imageID = int_escape($_POST["image_id"]);
        $noteID = int_escape($_POST["note_id"]);

        // validate parameters
        if (is_null($imageID) || !is_numeric($imageID) || is_null($noteID)  || !is_numeric($noteID)) {
            return;
        }

        $database->execute("UPDATE notes ".
                           "SET enable = ? ".
                           "WHERE image_id = ? AND id = ?", [0, $imageID, $noteID]);

        log_info("notes", "Note deleted {$noteID} by {$user->name}");
    }



    /*
    * HERE WE DELETE ALL NOTES FROM IMAGE
    */
    private function nuke_notes()
    {
        global $database, $user;
        $image_id = int_escape($_POST["image_id"]);
        $database->execute("DELETE FROM notes WHERE image_id = ?", [$image_id]);
        log_info("notes", "Notes deleted from {$image_id} by {$user->name}");
    }



    /*
    * HERE WE DELETE ALL REQUESTS FOR IMAGE
    */
    private function nuke_requests()
    {
        global $database, $user;
        $image_id = int_escape($_POST["image_id"]);

        $database->execute("DELETE FROM note_request WHERE image_id = ?", [$image_id]);

        log_info("notes", "Requests deleted from {$image_id} by {$user->name}");
    }

    /**
     * HERE WE ALL IMAGES THAT HAVE NOTES
     */
    private function get_notes_list(PageRequestEvent $event)
    {
        global $database, $config;

        $pageNumber = $event->try_page_num(1) - 1;

        $notesPerPage = $config->get_int('notesNotesPerPage');

        //$result = $database->get_all("SELECT * FROM pool_images WHERE pool_id=?", array($poolID));
        $result = $database->execute(
            "SELECT DISTINCT image_id".
                                     "FROM notes ".
                                     "WHERE enable = ? ".
                                     "ORDER BY date DESC LIMIT ?, ?",
            [1, $pageNumber * $notesPerPage, $notesPerPage]
        );

        $totalPages = ceil($database->get_one("SELECT COUNT(DISTINCT image_id) FROM notes") / $notesPerPage);

        $images = [];
        while ($row = $result->fetch()) {
            $images[] = [Image::by_id($row["image_id"])];
        }

        $this->theme->display_note_list($images, $pageNumber + 1, $totalPages);
    }

    /**
     * HERE WE GET ALL NOTE REQUESTS
     */
    private function get_notes_requests(PageRequestEvent $event)
    {
        global $config, $database;

        $pageNumber = $event->try_page_num(1) - 1;

        $requestsPerPage = $config->get_int('notesRequestsPerPage');


        //$result = $database->get_all("SELECT * FROM pool_images WHERE pool_id=?", array($poolID));


        $result = $database->execute(
            "
						SELECT DISTINCT image_id
						FROM note_request
						ORDER BY date DESC LIMIT ?, ?",
            [$pageNumber * $requestsPerPage, $requestsPerPage]
        );

        $totalPages = ceil($database->get_one("SELECT COUNT(*) FROM note_request") / $requestsPerPage);

        $images = [];
        while ($row = $result->fetch()) {
            $images[] = [Image::by_id($row["image_id"])];
        }

        $this->theme->display_note_requests($images, $pageNumber + 1, $totalPages);
    }



    /*
    * HERE WE ADD HISTORY TO TRACK THE CHANGES OF THE NOTES FOR THE IMAGES.
    */
    private function add_history($noteEnable, $noteID, $imageID, $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText)
    {
        global $user, $database;

        $reviewID = $database->get_one("SELECT COUNT(*) FROM note_histories WHERE note_id = ?", [$noteID]);
        $reviewID = $reviewID + 1;

        $database->execute(
            "
				INSERT INTO note_histories (note_enable, note_id, review_id, image_id, user_id, user_ip, date, x1, y1, height, width, note)
				VALUES (?, ?, ?, ?, ?, ?, now(), ?, ?, ?, ?, ?)",
            [$noteEnable, $noteID, $reviewID, $imageID, $user->id, $_SERVER['REMOTE_ADDR'], $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText]
        );
    }


    /**
     * HERE WE GET ALL HISTORIES.
     */
    private function get_histories(PageRequestEvent $event)
    {
        global $config, $database;

        $pageNumber = $event->try_page_num(1) - 1;

        $historiesPerPage = $config->get_int('notesHistoriesPerPage');

        //ORDER BY IMAGE & DATE
        $histories = $database->get_all(
            "SELECT h.note_id, h.review_id, h.image_id, h.date, h.note, u.name AS user_name ".
                                        "FROM note_histories AS h ".
                                        "INNER JOIN users AS u ".
                                        "ON u.id = h.user_id ".
                                        "ORDER BY date DESC LIMIT ?, ?",
            [$pageNumber * $historiesPerPage, $historiesPerPage]
        );

        $totalPages = ceil($database->get_one("SELECT COUNT(*) FROM note_histories") / $historiesPerPage);

        $this->theme->display_histories($histories, $pageNumber + 1, $totalPages);
    }


    /**
     * HERE WE THE HISTORY FOR A SPECIFIC NOTE.
     */
    private function get_history(PageRequestEvent $event)
    {
        global $config, $database;

        $noteID = $event->get_arg(1);
        $pageNumber = $event->try_page_num(2);

        $historiesPerPage = $config->get_int('notesHistoriesPerPage');

        $histories = $database->get_all(
            "SELECT h.note_id, h.review_id, h.image_id, h.date, h.note, u.name AS user_name ".
                                        "FROM note_histories AS h ".
                                        "INNER JOIN users AS u ".
                                        "ON u.id = h.user_id ".
                                        "WHERE note_id = ? ".
                                        "ORDER BY date DESC LIMIT ?, ?",
            [$noteID, $pageNumber * $historiesPerPage, $historiesPerPage]
        );

        $totalPages = ceil($database->get_one("SELECT COUNT(*) FROM note_histories WHERE note_id = ?", [$noteID]) / $historiesPerPage);

        $this->theme->display_history($histories, $pageNumber + 1, $totalPages);
    }

    /**
     * HERE GO BACK IN HISTORY AND SET THE OLD NOTE. IF WAS REMOVED WE RE-ADD IT.
     */
    private function revert_history(int $noteID, int $reviewID)
    {
        global $database;

        $history = $database->get_row("SELECT * FROM note_histories WHERE note_id = ? AND review_id = ?", [$noteID, $reviewID]);

        $noteEnable = $history['note_enable'];
        $noteID     = $history['note_id'];
        $imageID    = $history['image_id'];
        $noteX1     = $history['x1'];
        $noteY1     = $history['y1'];
        $noteHeight = $history['height'];
        $noteWidth  = $history['width'];
        $noteText   = $history['note'];

        $database->execute(
            "UPDATE notes ".
                           "SET enable = ?, x1 = ?, y1 = ?, height = ?, width = ?, note = ? ".
                           "WHERE image_id = ? AND id = ?",
            [1, $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText, $imageID, $noteID]
        );

        $this->add_history($noteEnable, $noteID, $imageID, $noteX1, $noteY1, $noteHeight, $noteWidth, $noteText);
    }
}
