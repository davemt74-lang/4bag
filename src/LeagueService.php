<?php

declare(strict_types=1);

namespace FourBag;

use PDO;
use RuntimeException;

final class LeagueService
{
    public function __construct(private PDO $db) {}

    public function listSeasons(): array
    {
        return $this->db->query("SELECT s.id,s.name,s.status,s.start_date,s.weeks,s.team_limit,s.players_per_team,s.registration_fee_cents,v.name venue_name,v.city,v.state,COUNT(r.id) registered_players,SUM(CASE WHEN r.board_purchase=1 THEN 1 ELSE 0 END) board_buyers FROM league_seasons s JOIN venues v ON v.id=s.venue_id LEFT JOIN registrations r ON r.season_id=s.id GROUP BY s.id ORDER BY s.start_date,s.id")->fetchAll();
    }

    public function createVenue(array $input): int
    {
        $name=trim((string)($input['name']??''));
        if($name==='') throw new RuntimeException('Venue name is required.');
        $stmt=$this->db->prepare("INSERT INTO venues(name,slug,city,state,status,created_at,updated_at) VALUES(:name,:slug,:city,:state,'approved',NOW(),NOW())");
        $stmt->execute(['name'=>$name,'slug'=>$this->slug($name).'-'.uniqid(),'city'=>trim((string)($input['city']??''))?:null,'state'=>trim((string)($input['state']??''))?:null]);
        return (int)$this->db->lastInsertId();
    }

    public function createSeason(array $input): int
    {
        $venueId=(int)($input['venue_id']??0);
        if($venueId<1) throw new RuntimeException('Valid venue_id is required.');
        $name=trim((string)($input['name']??'FourBag League'))?:'FourBag League';
        $stmt=$this->db->prepare("INSERT INTO league_seasons(venue_id,name,slug,status,start_date,weeks,team_limit,players_per_team,registration_fee_cents,created_at,updated_at) VALUES(:venue_id,:name,:slug,'registration_open',:start_date,:weeks,:team_limit,:players_per_team,5000,NOW(),NOW())");
        $stmt->execute(['venue_id'=>$venueId,'name'=>$name,'slug'=>$this->slug($name).'-'.uniqid(),'start_date'=>(string)($input['start_date']??date('Y-m-d',strtotime('+14 days'))),'weeks'=>max(1,(int)($input['weeks']??8)),'team_limit'=>max(2,(int)($input['team_limit']??8)),'players_per_team'=>max(1,(int)($input['players_per_team']??4))]);
        return (int)$this->db->lastInsertId();
    }

    public function registerPlayer(array $input): int
    {
        $seasonId=(int)($input['season_id']??0);$name=trim((string)($input['name']??''));$email=strtolower(trim((string)($input['email']??'')));
        if($seasonId<1||$name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('season_id, player name and valid email are required.');
        $this->db->beginTransaction();
        try{
            $stmt=$this->db->prepare("INSERT INTO players(name,email,created_at,updated_at) VALUES(:name,:email,NOW(),NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),updated_at=NOW()");
            $stmt->execute(['name'=>$name,'email'=>$email]);
            $q=$this->db->prepare('SELECT id FROM players WHERE email=:email LIMIT 1');$q->execute(['email'=>$email]);$playerId=(int)$q->fetchColumn();
            $board=!empty($input['board_purchase']);$join=(string)($input['join_type']??'solo');if(!in_array($join,['solo','friends','team'],true))$join='solo';
            $r=$this->db->prepare("INSERT INTO registrations(season_id,player_id,join_type,requested_group,board_purchase,registration_fee_cents,payment_status,created_at,updated_at) VALUES(:season_id,:player_id,:join_type,:requested_group,:board_purchase,:fee,:payment_status,NOW(),NOW()) ON DUPLICATE KEY UPDATE join_type=VALUES(join_type),requested_group=VALUES(requested_group),board_purchase=VALUES(board_purchase),updated_at=NOW()");
            $r->execute(['season_id'=>$seasonId,'player_id'=>$playerId,'join_type'=>$join,'requested_group'=>trim((string)($input['requested_group']??''))?:null,'board_purchase'=>$board?1:0,'fee'=>$board?0:5000,'payment_status'=>$board?'included_with_board':'pending']);
            if($board){$o=$this->db->prepare("INSERT INTO orders(player_id,season_id,order_type,subtotal_cents,status,created_at,updated_at) VALUES(:player_id,:season_id,'fourbag_set',19900,'pending',NOW(),NOW())");$o->execute(['player_id'=>$playerId,'season_id'=>$seasonId]);}
            $this->db->commit();return $playerId;
        }catch(\Throwable $e){$this->db->rollBack();throw $e;}
    }

    public function seasonRoster(int $seasonId): array
    {
        $s=$this->db->prepare("SELECT p.id,p.name,p.email,r.join_type,r.requested_group,r.board_purchase,r.payment_status,t.name team_name FROM registrations r JOIN players p ON p.id=r.player_id LEFT JOIN team_members tm ON tm.player_id=p.id AND tm.season_id=r.season_id LEFT JOIN teams t ON t.id=tm.team_id WHERE r.season_id=:season_id ORDER BY p.name");
        $s->execute(['season_id'=>$seasonId]);return $s->fetchAll();
    }

    public function dashboard(): array
    {
        return ['venues'=>(int)$this->db->query("SELECT COUNT(*) FROM venues WHERE status IN('approved','active')")->fetchColumn(),'seasons'=>(int)$this->db->query("SELECT COUNT(*) FROM league_seasons WHERE status IN('registration_open','active')")->fetchColumn(),'players'=>(int)$this->db->query('SELECT COUNT(*) FROM registrations')->fetchColumn(),'boardOrders'=>(int)$this->db->query("SELECT COUNT(*) FROM orders WHERE order_type='fourbag_set'")->fetchColumn()];
    }

    private function slug(string $value): string
    {
        $value=strtolower(trim($value));$value=preg_replace('/[^a-z0-9]+/','-',$value)??'';return trim($value,'-')?:'fourbag';
    }
}
