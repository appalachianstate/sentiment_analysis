<?php
    // This file is part of Moodle - http://moodle.org/
    //
    // Moodle is free software: you can redistribute it and/or modify
    // it under the terms of the GNU General Public License as published by
    // the Free Software Foundation, either version 3 of the License, or
    // (at your option) any later version.
    //
    // Moodle is distributed in the hope that it will be useful,
    // but WITHOUT ANY WARRANTY; without even the implied warranty of
    // MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    // GNU General Public License for more details.
    //
    // You should have received a copy of the GNU General Public License
    // along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

    /**
     * block_sentimentanalysis_task
     *
     * @author      Kara Beason <beasonke@appstate.edu>
     * @copyright   (c) 2019 Appalachian State Universtiy, Boone, NC
     * @license     GNU General Public License version 3
     * @package     block_sentimentanalysis
     */

    namespace block_sentimentanalysis\task;
    use \stdClass;
    use \context_user;
    use \core\message\message;

    defined('MOODLE_INTERNAL') || die();

    /**
     * For assignment ids specified in task custom data, export the
     * online-text submissions to individual files in a working dir
     * and execute a python script to do sentiment analysis on each
     * as well all of them collectively. Put resulting report output
     * in user's private file area.
     */
    class block_sentimentanalysis_task extends \core\task\adhoc_task
    {

        const DEFAULT_PYTHON_PATH   = "python";


        public function execute()
        {
            global $CFG, $DB;

            // If no path has been specified, use default, i.e. rely on
            // whatever is found in $PATH
            $block_config = get_config('block_sentimentanalysis');
            $pythonpath = empty($block_config->pythonpath) ? self::DEFAULT_PYTHON_PATH : $block_config->pythonpath;

            // Create a temp working directory specifically for this
            // task
            $taskdir = make_temp_directory("sentimentanalysis/{$this->get_id()}");
            if (!$taskdir) {
                mtrace("... Unable to create task temporary directory.");
                return;
            }

            // Assignment id values passed in task's custom data as
            // an array.
            $assignids = $this->get_custom_data();
            if (!$assignids) {
                mtrace("... No assignment id values found for task.");
                return;
            }

            // Iterate over assignment ids, exporting the submissions
            // in a separate working dir for each assignment
            foreach ($assignids as $assignid) {

                // Validate the id and fetch the assignment name
                $assignrec = $DB->get_record('assign', array('id' => $assignid));
                if (!$assignrec) {
                    mtrace("... Invalid assignment id: {$assignid}.");
                    continue;
                }

                // Make temp directory and write all assignment submissions to it
                // so the python script can just iterate over files in directory.
                $assigndir = make_temp_directory("sentimentanalysis/{$this->get_id()}/{$assignid}");
                if (!$assigndir) {
                    mtrace("... Unable to create temporary directory for assignment: {$assignid}.");
                    continue;
                }

                $sql = "SELECT olt.id, usr.username, usr.firstname, usr.lastname, olt.onlinetext
                    FROM mdl_assignsubmission_onlinetext olt
                    INNER JOIN mdl_assign_submission sub ON sub.id = olt.submission
                    INNER JOIN mdl_user usr ON usr.id = sub.userid
                    WHERE olt.assignment = :assignid AND sub.status = 'submitted'";

                $rs = $DB->get_recordset_sql($sql, array("assignid" => $assignid));
                if (!$rs->valid()) {
                    $rs->close();
                    mtrace("... No submissions found for assignment id: {$assignid}.");
                    continue;
                }

                $collective = fopen("{$assigndir}/collective.txt", "w");
                // First line of the collective file is assignment name
                fwrite($collective, $assignrec->name . "\n");

                foreach ($rs as $record) {
                    // Submission file.
                    $file = fopen("{$assigndir}/{$record->id}.txt", "w");
                    fwrite($file, "{$record->username}\n");
                    fwrite($file, "{$record->lastname}, {$record->firstname}\n");
                    fwrite($file, strip_tags($record->onlinetext));
                    fclose($file);
                    // Collective file.
                    fwrite($collective, strip_tags($record->onlinetext) . "\n");
                }
                fclose($collective);
                $rs->close();

                // Execute python script to process the files.
                $output = null;
                $return = null;

                exec("export PYTHONPATH='{$CFG->dirroot}/blocks/sentimentanalysis/packages'; {$pythonpath} {$CFG->dirroot}/blocks/sentimentanalysis/sentimentanalysis.py '{$assigndir}'", $output, $return);
                if ($return != 0) {
                    mtrace("... Sentiment analylsis failed on task id {$this->get_id()}, assign id {$record->id}.");
                    continue;
                }

                mtrace("... Sentiment analylsis completed.");

                // Create file records to save the files produced by the python script
                // into the teacher's private file area.
                $this->create_file_records($assigndir, $assignrec->name);

            }

            $this->notify_user();

            // Clean up after ourselves
            $this->removetempdir($taskdir);

        }

        /**
         * Put the report output files in the user's private file area
         *
         * @param string $assigndir Path to the task-assignment working directory
         * @param string $assignname Assignment name
         */
        private function create_file_records($assigndir, $assignname)
        {

            $now = time();

            //$filename, $assignmentdir, $assign_name, $datetime, $ext
            $fs = get_file_storage();
            $userid = $this->get_userid();
            $context = context_user::instance($userid);

            foreach(array(".pdf", ".csv") as $extension) {

                $filename = "output{$extension}";
                if (!is_readable("{$assigndir}/{$filename}")) {
                    mtrace("... File {$assigndir}/{$filename} not readable.");
                    continue;
                }

                $record = new stdClass();
                $record->filearea   = "private";
                $record->component  = "user";
                $record->filepath   = "/Sentiment Analysis/Task-{$this->get_id()} (" . date("m-d-y Hi", $now) . ")/";
                $record->itemid     = 0;
                $record->contextid  = $context->id;
                $record->userid     = $userid;

                $record->filename   = $fs->get_unused_filename(
                    $context->id, $record->component, $record->filearea, $record->itemid, $record->filepath,
                    "{$assignname}{$extension}");

                if (!$fs->create_file_from_pathname($record, "{$assigndir}/{$filename}")) {
                    mtrace("... Error creating file {$assigndir}/{$filename}.");
                }

            }

        }

        /**
         * Notify user to let them know their reports are completed
         * and uploaded in their private file section.
         */
        private function notify_user()
        {

            $message = new message();

            $message->component         = 'moodle';
            $message->name              = 'instantmessage';
            $message->userfrom          = get_admin();
            $message->userto            = $this->get_userid();
            $message->subject           = 'Sentiment Analysis Complete';
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->smallmessage      =
            $message->fullmessage       = 'Please check the "Sentiment Analysis" folder in your private file area to view reports.';
            $message->fullmessagehtml   = '<p>' . $message->fullmessage . '</p>';

            message_send($message);

        }

        /**
         * Remove all contents in our temp directory, then the directory itself
         *
         * @param string $dirpath Path to the working directory
         */
        private function removetempdir($dirpath)
        {
            global $CFG;

            if ((stripos($dirpath, $CFG->tempdir) !== 0) || !is_dir($dirpath)) {
                return;
            }

            foreach(scandir($dirpath) as $dirobject) {
                if ($dirobject == "." || $dirobject == "..") {
                    continue;
                }
                if (is_dir("{$dirpath}/{$dirobject}") && !is_link("{$dirpath}/{$dirobject}")) {
                    $this->removetempdir("{$dirpath}/{$dirobject}");
                } else {
                    unlink("{$dirpath}/{$dirobject}");
                }
            }

            rmdir($dirpath);

        }

    }
