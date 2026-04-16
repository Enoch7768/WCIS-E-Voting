<?php
session_start();
require '../includes/db.php';
require '../includes/auth.php';
require '../includes/csrf.php';
require_teacher();

$notice = null; $error = null;

function valid_role($r){ return in_array($r, ['admin','teacher','student'], true); }

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['pos_id'])) {
    $pos_id = (int)$_GET['pos_id'];
    $stmt = $pdo->prepare('SELECT id, full_name, image FROM candidates WHERE position_id=? ORDER BY id ASC');
    $stmt->execute([$pos_id]);
    header('Content-Type: application/json');
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) { $error='Invalid CSRF token.'; }
    else {
        $action=$_POST['action']??'';
        try{
            if($action==='create' || $action==='update'){
                $id=(int)($_POST['id']??0);
                $full_name=trim($_POST['full_name']??'');
                $email=trim($_POST['email']??'');
                $role=$_POST['role']??'student';
                $password=$_POST['password']??'';
                if($full_name==='' || !filter_var($email,FILTER_VALIDATE_EMAIL) || !valid_role($role)) throw new Exception('Invalid input.');
                if($action==='create'){
                    if(!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*\W).{8,}$/',$password)) throw new Exception('Weak password.');
                    $hash=password_hash($password,PASSWORD_DEFAULT);
                    $stmt=$pdo->prepare('INSERT INTO users (full_name,email,password,role,is_verified) VALUES (?,?,?,?,1)');
                    $stmt->execute([$full_name,$email,$hash,$role]);
                } else {
                    if($password!==''){
                        if(!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*\W).{8,}$/',$password)) throw new Exception('Weak password.');
                        $hash=password_hash($password,PASSWORD_DEFAULT);
                        $stmt=$pdo->prepare('UPDATE users SET full_name=?, email=?, role=?, password=? WHERE id=?');
                        $stmt->execute([$full_name,$email,$role,$hash,$id]);
                    } else{
                        $stmt=$pdo->prepare('UPDATE users SET full_name=?, email=?, role=? WHERE id=?');
                        $stmt->execute([$full_name,$email,$role,$id]);
                    }
                }
                if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
                    header('Content-Type: application/json'); echo json_encode(['status'=>'success']); exit;
                }
            }
            if($action==='delete'){
                $id=(int)($_POST['id']??0);
                if($id<=0 || $id===$_SESSION['user_id']) throw new Exception('Invalid delete.');
                $stmt=$pdo->prepare('DELETE FROM users WHERE id=?'); $stmt->execute([$id]);
                if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){header('Content-Type: application/json'); echo json_encode(['status'=>'success']); exit;}
            }

            if($action==='save_position'){
                $id=(int)($_POST['id']??0);
                $name=trim($_POST['name']??''); if($name==='') throw new Exception('Position required.');
                if($id>0){ $stmt=$pdo->prepare('UPDATE positions SET name=? WHERE id=?'); $stmt->execute([$name,$id]); }
                else { $stmt=$pdo->prepare('INSERT INTO positions(name) VALUES(?)'); $stmt->execute([$name]); }
                echo json_encode(['status'=>'success']); exit;
            }
            if($action==='delete_position'){
                $id=(int)($_POST['id']??0);
                $stmt=$pdo->prepare('DELETE FROM positions WHERE id=?'); $stmt->execute([$id]);
                echo json_encode(['status'=>'success']); exit;
            }

            if($action==='save_candidate'){
                $pos=(int)($_POST['position_id']??0); $full_name=trim($_POST['full_name']??'');
                if($pos<=0 || $full_name==='') throw new Exception('Invalid candidate.');
                $imagePath=null;
                if(isset($_FILES['image']) && $_FILES['image']['error']===0){
                    $ext=pathinfo($_FILES['image']['name'],PATHINFO_EXTENSION);
                    if(!is_dir('uploads/candidates')) mkdir('uploads/candidates',0755,true);
                    $imagePath='uploads/candidates/'.uniqid().'.'.$ext;
                    move_uploaded_file($_FILES['image']['tmp_name'],$imagePath);
                }
                $stmt=$pdo->prepare('INSERT INTO candidates(position_id,full_name,image) VALUES(?,?,?)'); 
                $stmt->execute([$pos,$full_name,$imagePath]);
                echo json_encode(['status'=>'success']); exit;
            }
            if($action==='delete_candidate'){
                $id=(int)($_POST['id']??0);
                $stmt=$pdo->prepare('DELETE FROM candidates WHERE id=?'); $stmt->execute([$id]);
                echo json_encode(['status'=>'success']); exit;
            }
        } catch(Throwable $ex){
            $error=$ex->getMessage();
            if(!empty($_SERVER['HTTP_X_REQUESTED_WITH'])){
                header('Content-Type: application/json'); echo json_encode(['status'=>'error','message'=>$error]); exit;
            }
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_position_id'])) {
    $posId = (int)$_POST['reset_position_id'];
    $stmt = $pdo->prepare("DELETE v FROM votes v 
                JOIN candidates c ON v.candidate_id = c.id 
                WHERE c.position_id = ?");
    $stmt->execute([$posId]);
    $notice = "Votes reset for position ID $posId.";
}


$users=$pdo->query('SELECT id,full_name,email,role,is_verified,created_at FROM users ORDER BY id DESC')->fetchAll();
$positions=$pdo->query('SELECT id,name FROM positions ORDER BY id DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="shortcut icon" href="../WCIS_LOGO-1-removebg-preview.png" type="image/x-icon">
<title>Teacher Dashboard</title>
<style>
      :root{
            --bg:#0b1020; 
            --bg-soft:#11162b; 
            --card:#151b34; 
            --muted:#aab1c7; 
            --text:#e9ecf8;
            --primary:#6e8bff; 
            --primary-700:#3a5dff; 
            --danger:#ff5b6e; 
            --success:#4cd4a8;
            --radius:16px; 
            --shadow-lg:0 20px 60px rgba(0,0,0,.45); 
            --shadow-md:0 10px 30px rgba(0,0,0,.35);
}

*{
  box-sizing:border-box;

}
html,body{
  height:100%;
}

body{
  margin:0;
  font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Helvetica,Arial;
  background:
    radial-gradient(1200px 800px at 80% -10%, rgba(110,139,255,.18), transparent),
    radial-gradient(900px 600px at -10% 110%, rgba(76,212,168,.08), transparent),
    var(--bg);
  color:var(--text)
}

.container-center{
  min-height:100vh;
  display:grid;
  place-items:center;
  padding:40px 16px;
}
.card{
  width:min(1100px,96vw);
  background:linear-gradient(180deg,rgba(255,255,255,.04),rgba(255,255,255,.02));
  backdrop-filter:blur(10px);
  border:1px solid rgba(255,255,255,.08);
  border-radius:var(--radius);
  box-shadow:var(--shadow-lg);
}
.card--narrow{
  width:min(420px,94vw);
}
.card__header{
  padding:20px 28px 0;
}
.card__body{
  padding:28px;
}
.card__title{
  margin:0;
  font-size:22px;
  letter-spacing:.3px;
}
.card__sub{
  margin:6px 0 0;
  color:var(--muted);
  font-size:13px
}
.header{
  display:flex;
  align-items:center;
  gap:12px;
}

.logo{
  width:38px;
  height:38px;
  border-radius:12px;
  background:linear-gradient(135deg,var(--primary),#9eaaff);
  box-shadow:0 6px 24px rgba(110,139,255,.45);
  display:grid;
  place-items:center;
  overflow:hidden;
}
.logo img{
  width:100%;
  height:90%;
  object-fit:contain;
}
.logo--placeholder::after{
  content:"LOGO";
  font-size:10px;
  letter-spacing:.6px;
}
.brand{
  font-weight:700;
  letter-spacing:.6px;
}

.form{
  display:grid;
  gap:14px;
  margin-top:18px;
}
.label{
  font-size:13px;
  color:var(--muted);
  margin-bottom:6px;
  display:block;
}
.input,.select{
  width:100%;
  padding:12px 14px;
  border-radius:12px;
  color:var(--text);
  background:#0f142a;
  border:1px solid rgba(255,255,255,.08);
  outline:none;
}
.input:focus,.select:focus{
  box-shadow:0 0 0 3px rgba(110,139,255,.25);
  border-color:rgba(110,139,255,.6);
}

.btn{
  cursor:pointer;
  user-select:none;
  border:none;
  border-radius:12px;
  padding:12px 16px;
  font-weight:700;
}
.btn--primary{
  background:linear-gradient(180deg,var(--primary),var(--primary-700));
  color:#fff;
  box-shadow:0 10px 30px rgba(110,139,255,.35);
}
.btn--ghost{
  background:transparent;
  color:var(--muted);
  border:1px solid rgba(255,255,255,.12);
}
.btn--danger{
  background:linear-gradient(180deg,#ff7686,#ff475f);
  color:#fff;
}
.btn--success{
  background:linear-gradient(180deg,#67e3b5,#3ccf9e);
  color:#102016;
}

.row{
  display:grid;
  gap:12px;
  grid-template-columns:1fr 1fr;
}
@media (max-width:640px){
  .row{
    grid-template-columns:1fr
  }
}
.helper-text{
  font-size:12px;
  color:var(--muted)
}

.alert{
  padding:12px 14px;
  border-radius:12px;
  font-size:14px
}
.alert--error{
  background:rgba(255,91,110,.12);
  border:1px solid rgba(255,91,110,.3);
  color:#ffb3bd;
}
.alert--success{
  background:rgba(76,212,168,.12);
  border:1px solid rgba(76,212,168,.3);
  color:#b8f3e1;
}

.table-wrap{
  overflow:auto;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.08)
}
.table{
  width:100%;
  border-collapse:collapse
}
.table th,.table td{
  padding:14px 12px;
  border-bottom:1px solid rgba(255,255,255,.06);
  text-align:left;
  font-size:14px
}
.table th{
  color:var(--muted);
  font-weight:600;
  background:rgba(255,255,255,.02);
  position:sticky;
  top:0;
  backdrop-filter:blur(6px)
}
.table tr:hover td{
  background:rgba(255,255,255,.03)
}

.toolbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
  margin:16px 0 10px
}
.search{
  display:flex;
  gap:10px;
  align-items:center;
  background:#0f142a;
  border:1px solid rgba(255,255,255,.08);
  border-radius:12px;
  padding:8px 12px
}
.search input{
  background:transparent;
  border:none;
  outline:none;
  color:var(--text)
}

.badge{
  padding:6px 10px;
  border-radius:100px;
  font-size:12px;
  border:1px solid rgba(255,255,255,.12);
  color:var(--muted)
}
.badge--admin{
  color:#ffd8b1;
  border-color:#ffd8b1
}
.badge--teacher{
  color:#b1d9ff;
  border-color:#b1d9ff
}
.badge--student{
  color:#b1ffcf;
  border-color:#b1ffcf
}

.modal{
  position:fixed;
  inset:0;
  display:none;
  place-items:center;
  background:rgba(5,8,18,.55)
}
.modal.open{
  display:grid
}
.modal__content{
  width:min(560px,94vw);
  background:var(--card);
  border:1px solid rgba(255,255,255,.12);
  border-radius:18px;
  box-shadow:var(--shadow-md)
}
.modal__head{
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:18px 20px;
  border-bottom:1px solid rgba(255,255,255,.06)
}
.modal__body{
  padding:18px 20px
}
.modal__title{
  margin:0;
  font-size:18px
}

.footer{
  margin-top:24px;
  display:flex;
  justify-content:center;
  gap:12px;
  color:var(--muted);
  font-size:12px
}
.footer a{
  color:var(--muted)
}

.logo img {
  transition: transform 0.3s ease, filter 0.3s ease;
}

.logo:hover img {
  transform: scale(1.08); 
  filter: drop-shadow(0 0 12px rgba(110,139,255,0.6));
}


@keyframes pulse {
  0% { transform: scale(1); filter: drop-shadow(0 0 8px rgba(110,139,255,0.4)); }
  50% { transform: scale(1.03); filter: drop-shadow(0 0 16px rgba(110,139,255,0.6)); }
  100% { transform: scale(1); filter: drop-shadow(0 0 8px rgba(110,139,255,0.4)); }
}

.logo.pulse img {
  animation: pulse 2.5s infinite ease-in-out;
}
</style>
</head>
<body>
  <main class="container-center">
    <section class="card">
    <header class="card__header">
    <div class="header" style="justify-content: space-between; width:100%">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="logo"><img src="../WCIS_LOGO-1-removebg-preview.png"></div>
        <div>
      <h1 class="card__title">Teacher Dashboard</h1>
      <p class="card__sub">Voting Management</p>
    </div>
  </div>
  <a class="btn btn--ghost" href="../auth/logout.php">Logout</a>
</div>
</header>
<div class="card__body">
<div id="results">
  <h2>Election Results</h2>
 <table border="1" cellpadding="10" cellspacing="0" width="100%">
    <thead>
      <tr>
        <th>Position</th>
        <th>Candidate</th>
        <th>Total Votes</th>
        <th>Winner</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $posQ = $pdo->query("SELECT * FROM positions");
      
      $chartLabels = [];
      $chartVotes = [];
      $tiedCandidates = [];

      while ($pos = $posQ->fetch(PDO::FETCH_ASSOC)) {
          $position_id = $pos['id'];
          $position_name = htmlspecialchars($pos['name']);

          $candQ = $pdo->prepare("
              SELECT c.id, c.full_name, COUNT(v.id) AS total_votes 
              FROM candidates c
              LEFT JOIN votes v ON v.candidate_id = c.id
              WHERE c.position_id = ?
              GROUP BY c.id
          ");
          $candQ->execute([$position_id]);
          $candidates = $candQ->fetchAll(PDO::FETCH_ASSOC);

          if (!empty($candidates)) {
              $maxVotes = max(array_column($candidates, 'total_votes'));
              $topCandidates = array_filter($candidates, fn($c) => $c['total_votes'] == $maxVotes);
              $isTie = count($topCandidates) > 1;
              $winnerId = $isTie ? null : current($topCandidates)['id'];
              $winnerText = $isTie ? "Tie" : "";
          }

          foreach ($candidates as $c) {
              echo "<tr>";
              echo "<td>{$position_name}</td>";
              echo "<td>".htmlspecialchars($c['full_name'])."</td>";
              echo "<td>{$c['total_votes']}</td>";
              echo "<td>" . ($winnerId === $c['id'] ? "✅ Winner" : $winnerText) . "</td>";
              echo "</tr>";

              $chartLabels[] = "{$position_name}: {$c['full_name']}";
              $chartVotes[] = (int)$c['total_votes'];
              $tiedCandidates[] = $isTie && $c['total_votes'] == $maxVotes;
          }
      }
      ?>
    </tbody>
  </table>

  <canvas id="resultsChart"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {
    const ctx = document.getElementById('resultsChart').getContext('2d');

    const labels = <?php echo json_encode($chartLabels); ?>;
    const votes = <?php echo json_encode($chartVotes); ?>;
    const tied = <?php echo json_encode($tiedCandidates); ?>;

    const colors = votes.map((v, i) => tied[i] ? 'rgba(255, 99, 132, 0.7)' : 'rgba(54, 162, 235, 0.7)');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Votes',
                data: votes,
                backgroundColor: colors
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const idx = context.dataIndex;
                            let text = context.dataset.label + ': ' + context.parsed.y;
                            if (tied[idx]) text += ' (Tie)';
                            return text;
                        }
                    }
                }
            }
        }
    });
});
</script>
</div></section>
<script>
const imageInput = document.getElementById('candidateImageInput');
const preview = document.getElementById('candidatePreview');
imageInput.addEventListener('change', () => {
  const file = imageInput.files[0];
  if (file) {
    const reader = new FileReader();
    reader.onload = e => {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(file);
  } else {
    preview.style.display = 'none';
  }
});
</script>
</body>
</html>
