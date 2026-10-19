<?php
declare(strict_types=1);
namespace YcNote;

use RuntimeException;
use Throwable;
use ZipArchive;

/** Notebook projections use immutable revisions from one manifest snapshot. */
trait Notebooks
{
    private function notebookHeads(int $uid, string $path): array
    {
        self::validPath($path);
        if (!preg_match('/\.sbn2?$/D', $path)) throw new ApiError(422, '無效的筆記。');
        $heads=array_column($this->manifest($uid), null, 'path');
        if (!isset($heads[$path]) || $heads[$path]['deleted']) throw new ApiError(404, '找不到筆記。');
        return $heads;
    }

    private function notebookSources(string $path, array $heads): array
    {
        $sources=[];
        foreach ($heads as $name=>$head) {
            if (!$head['deleted'] && ($name===$path || preg_match('/^'.preg_quote($path,'/').'\.[0-9]+$/D', $name))) $sources[$name]=$head;
        }
        ksort($sources, SORT_STRING);
        return $sources;
    }

    private function checkedBlob(int $uid, string $path, array $head): array
    {
        $file=$this->download($uid,$path,(int)$head['revision']);
        if ((int)filesize($file['file'])!==(int)$head['size'] || !hash_equals($head['blob_hash'],(string)hash_file('sha256',$file['file']))) throw new RuntimeException('Stored notebook checksum mismatch');
        return $file;
    }

    private function verifiedPdf(int $uid, string $path, array $heads, bool $verifyContent=true): array
    {
        $pdf=$heads[$path.'.pdf']??null;
        $stamp=$heads[$path.'.pdf.json']??null;
        if (!$pdf || $pdf['deleted'] || !$stamp || $stamp['deleted'] || $stamp['size']>262144) throw new ApiError(409,'PDF 尚未更新。');
        $metadata=$this->checkedBlob($uid,$path.'.pdf.json',$stamp);
        $data=json_decode((string)file_get_contents($metadata['file']),true);
        if (!is_array($data) || ($data['v']??null)!==1 || !is_array($data['source']??null) || ($data['pdf_hash']??null)!==$pdf['blob_hash']) throw new ApiError(409,'PDF 尚未更新。');
        $expected=array_map(static fn($f)=>$f['blob_hash'],$this->notebookSources($path,$heads));
        $actual=$data['source']; ksort($actual, SORT_STRING);
        if ($actual!==$expected) throw new ApiError(409,'PDF 尚未更新。');
        $file=$verifyContent?$this->checkedBlob($uid,$path.'.pdf',$pdf):$this->download($uid,$path.'.pdf',(int)$pdf['revision']);
        $handle=fopen($file['file'],'rb');
        try { if (!$handle || fread($handle,5)!=='%PDF-') throw new ApiError(409,'PDF 無法使用。'); }
        finally { if ($handle) fclose($handle); }
        return $file;
    }

    public function notebooks(int $uid): array
    {
        $this->rentalUser($uid);
        $heads=array_column($this->manifest($uid),null,'path');
        $books=[];
        foreach ($heads as $path=>$head) {
            if (!preg_match('/\.sbn2?$/D',$path)) continue;
            $sources=$this->notebookSources($path,$heads);
            $cover=$heads[$path.'.p']??null;
            $ready=false;
            if (!$head['deleted']) {
                try { $this->verifiedPdf($uid,$path,$heads,false); $ready=true; }
                catch (ApiError $e) { if ($e->status!==409) throw $e; }
                catch (RuntimeException $e) { error_log('[YC Note PDF] '.get_class($e)); }
            }
            $books[]=$head+[
                'title'=>preg_replace('/\.sbn2?$/D','',basename($path)),
                'folder'=>dirname($path)==='/'?'':trim(dirname($path),'/'),
                'total_size'=>array_sum(array_column($sources,'size')),
                'cover'=>!$head['deleted'] && $cover && !$cover['deleted'],
                'pdf_ready'=>$ready,
            ];
        }
        usort($books,static fn($a,$b)=>$b['updated_at']<=>$a['updated_at'] ?: strcmp($a['path'],$b['path']));
        return $books;
    }

    public function notebookPdf(int $uid,string $path): array
    {
        $this->rentalUser($uid);
        return $this->verifiedPdf($uid,$path,$this->notebookHeads($uid,$path));
    }

    public function notebookCover(int $uid,string $path): array
    {
        $this->rentalUser($uid);
        $heads=$this->notebookHeads($uid,$path);
        $head=$heads[$path.'.p']??null;
        if (!$head || $head['deleted'] || $head['size']>8*1024*1024) throw new ApiError(404,'找不到封面。');
        $file=$this->checkedBlob($uid,$path.'.p',$head);
        $info=getimagesize($file['file']);
        if (!$info || !in_array($info['mime'],['image/png','image/jpeg'],true) || $info[0]>8192 || $info[1]>8192) throw new ApiError(404,'找不到封面。');
        return $file+['type'=>$info['mime']];
    }

    /** Temporary archive belongs to this request; caller must unlink it. */
    public function notebookArchive(int $uid,string $path): array
    {
        $this->rentalUser($uid);
        $sources=$this->notebookSources($path,$this->notebookHeads($uid,$path));
        if (!class_exists(ZipArchive::class)) throw new RuntimeException('ZIP support unavailable');
        $directory=dirname($this->config['storage_path']).'/exports';
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Export directory unavailable');
        $temp=tempnam($directory,'notebook-');
        if (!$temp) throw new RuntimeException('Export file unavailable');
        chmod($temp,0600);
        $zip=new ZipArchive();
        try {
            if ($zip->open($temp,ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Archive unavailable');
            $main='main'.(str_ends_with($path,'.sbn2')?'.sbn2':'.sbn');
            foreach ($sources as $name=>$head) {
                $file=$this->checkedBlob($uid,$name,$head);
                if (!$zip->addFile($file['file'],$main.substr($name,strlen($path)))) throw new RuntimeException('Archive entry unavailable');
            }
            if (!$zip->close()) throw new RuntimeException('Archive write failed');
            return ['file'=>$temp,'size'=>filesize($temp)];
        } catch (Throwable $e) { unset($zip); if (is_file($temp)) unlink($temp); throw $e; }
    }
}
