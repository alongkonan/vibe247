export default function handler(req, res) {
    // 1. ดึงเวลาปัจจุบันในโซนเวลา กรุงเทพฯ (Asia/Bangkok)
    const date = new Date();
    const formatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Bangkok',
        hour: '2-digit',
        hourCycle: 'h23' // บังคับให้เป็นรูปแบบ 00 - 23 (เหมือน date('H') ใน PHP)
    });
    
    const currentHour = formatter.format(date);
    
    // 2. สร้างลิงก์ URL ปลายทาง
    const remoteUrl = `http://news.pakeefm.org/news${currentHour}.mp3`;

    // 3. สั่ง Redirect ส่งคนไปที่ลิงก์ต้นทางทันที
    res.redirect(302, remoteUrl);
}
