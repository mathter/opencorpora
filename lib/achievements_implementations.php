<?php

require_once(__DIR__.'/lib_achievements.php');

class AistAchievement extends Achievement implements SignedUpListenerInterface {

    use GivenOnTrigger;

    public $short_title = "Аист";
    public $caption = "За регистрацию";
    public $css_class = "aist";

}

class BobrAchievement extends Achievement implements TaskDoneListenerInterface {
    use AchievementWithLevels;

    public $short_title = "Бобёр";
    public $caption = "За трудолюбие";
    public $css_class = "bobr";

    public $column_description = "количество заданий, которое нужно сделать";

    private function _tasks_done() {
        $res = sql_pe("SELECT COUNT(*) AS cnt
            FROM morph_annot_instances
            WHERE user_id=? AND answer > 0", array($this->user_id));
        return $res[0]['cnt'];
    }

    public function dispatch($args) {
        // update progress and maybe level
        // if level was updated, toggle seen
        $grades = $this->grades();

        $tasks_done = $this->_tasks_done();

        $counter = 0;
        $progress = 0;
        foreach ($grades as $level0 => $count) {
            if ($tasks_done >= $count) {
                $counter++;

                if (isset($grades[$level0 + 1])
                    && $tasks_done < $grades[$level0 + 1]) {

                    $progress = ceil(
                        ($tasks_done - $count) * 100 / ($grades[$level0 + 1] - $count)
                    );
                    break;
                }
            }
        }

        if ($progress < 0) // this might happen on level 0
            $progress = 0;
        else if ($progress > 100)
            $progress = 100;

        $this->progress = $progress;
        if ($counter > $this->level) {
            $this->level = $counter;
            $this->progress = 0;
            $this->seen = FALSE;
        }
        if ($this->level)
            $this->push();
    }

    public function how_to_get_next() {
        $grades = $this->grades();
        if ($this->level == count($this->grades)) return FALSE;

        $tasks_done = $this->_tasks_done();

        $next_level = $this->level + 1;
        if (isset($grades[$next_level])) {
            $required = $grades[$this->level] - $tasks_done;
            return "Для получения $next_level уровня осталось сделать $required ".$this->_tasks_spelling($required);
        }
        return FALSE;
    }
}

class ChameleonAchievement extends Achievement implements TaskDoneListenerInterface {
    use AchievementWithLevels;

    public $short_title = "Хамелеон";
    public $caption = "За разнообразие";
    public $css_class = "chameleon";

    public $amount_of_work = "%d по %d";
    public $column_description = "количество типов пулов и количество заданий в каждом";

    private function _get_counts() {
        $res = sql_pe("
            SELECT COUNT(instance_id)
            FROM morph_annot_instances
            LEFT JOIN morph_annot_samples
                USING (sample_id)
            LEFT JOIN morph_annot_pools
                USING (pool_id)
            WHERE user_id = ?
                AND answer > 0
            GROUP BY pool_type
            ORDER BY COUNT(instance_id) DESC
        ", array($this->user_id));

        $counts = array_map(function($type) {
            return $type[0];
        }, $res);
        return $counts;
    }
    public function dispatch($args) {
        $counts = $this->_get_counts();

        $level = 0;
        $progress = 0;
        $grades = $this->fetch_grades();
        foreach ($grades as $level0 => $params) {
            list($types, $min) = $params;
            if (count($counts) < $types) break;
            foreach (array_slice($counts, 0, $types) as $num) {
                if ($num < $min) break 2;
            }
            $level++;
        }

        // shall we update level?
        if ($level > $this->level) {
            $this->level = $level;
            $this->progress = 0;
            $this->seen = FALSE;
        // shall we update progress?
        } else if ($this->level && isset($grades[$this->level])) {
            // means that next level can be achieved)
            list($types, $min) = $grades[$this->level];
            $has_to_do = $min * $types;
            $did = array_sum(
                array_map(function($e) use ($min) {
                    return $e > $min ? $min : $e;
                }, array_slice($counts, 0, $types)));

            $base_for_level = $grades[$this->level - 1][0] * $grades[$this->level - 1][1];
            $progress = ceil(($did - $base_for_level) * 100 / ($has_to_do - $base_for_level));

            if ($progress > $this->progress)
                $this->progress = $progress;
        }

        if ($this->level) $this->push();
    }

    public function how_to_get_next() {
        $grades = $this->fetch_grades();
        if ($this->level == count($grades)) return FALSE;

        $next_level = $this->level + 1;
        $next = $grades[$this->level];

        $counts = array_slice($this->_get_counts(), 0, $next[1]);
        $required_types = $next[0] - count(array_filter($counts,
            function($typecount) use ($next) {
                return $typecount >= $next[1];
            }
        ));
        return "Для получения $next_level уровня нужно сделать ".
        ($required_types > 1 ? "по ": "").
        "{$next[1]} заданий в $required_types ".$this->_types_spelling($required_types)." пулов";
    }
}

class DogAchievement extends Achievement implements MonthPassedListenerInterface, TaskDoneListenerInterface {
    use AchievementWithLevels;

    public $short_title = "Пёс";
    public $caption = "За преданность";
    public $css_class = "dog";

    public $column_description = "сколько заданий надо сделать в месяц (каждый месяц уровень увеличивается)";
    public $amount_of_work = "%d";

    private function _get_count_for_last_month() {
        $res = sql_pe("SELECT MONTH(FROM_UNIXTIME(ts_finish)) AS month,
                   YEAR(FROM_UNIXTIME(ts_finish)) AS year, COUNT(*) as cnt
                FROM morph_annot_instances
                WHERE user_id=? AND answer > 0
                GROUP BY year, month
                HAVING month = MONTH(NOW()) AND year = YEAR(NOW())
                ORDER BY year, month", array($this->user_id));

        return $res[0]['cnt'];
    }

    public function dispatch($args) {
        $grades = $this->fetch_grades();
        $next = isset($grades[$this->level]) ? $grades[$this->level] : FALSE;
        $current = $grades[$this->level - 1];
        $count = $this->_get_count_for_last_month();

        if ($args['event_type'] == EventTypes::TASK_DONE) {
            if ($this->progress == 100 || !$this->level || !$next) return; // wait for another event

            $progress = ceil($count * 100 / $next[0]);
            if ($progress > 100) $progress = 100;
            $this->progress = $progress;
            $this->push();

        }
        else if ($args['event_type'] == EventTypes::MONTH_PASSED) {
            if (($this->progress == 100 && $next)
                || (!$this->level && $count >= $next[0])) {
                $this->level++;
                $this->progress = 0;
                $this->seen = FALSE;
                $this->push();
            }
            else if ($this->progress != 100 && $this->level)
                $this->progress = 0;
                $this->push();
        }
    }

    public function how_to_get_next() {
        $grades = $this->fetch_grades();
        if ($this->level == count($grades)) return FALSE;

        $next_level = $this->level + 1;
        $next = $grades[$this->level];
        $required = $next[0] - $this->_get_count_for_last_month();
        if ($required < 0) $required = 0;

        if ($required)
            return "Для получения $next_level уровня в этом месяце осталось сделать $required ".$this->_tasks_spelling($required);

        return "Для получения $next_level уровня осталось дождаться следующего месяца";
    }

}

class WantMoreAchievement extends Achievement implements WantMoreListenerInterface {
    use GivenOnTrigger;

    public $short_title = "Хочу ещё!";
    public $caption = "Хочу еще!";
    public $css_class = "wantmore";
}

class FishAchievement extends Achievement implements JoinedTeamListenerInterface {
    use GivenOnTrigger;

    public $short_title = "Рыбы";
    public $caption = "За вступление в команду";
    public $css_class = "fish";

}