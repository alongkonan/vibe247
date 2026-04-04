export default function handler(req, res) {
    // 1. ดึงเวลาปัจจุบันในโซนเวลา กรุงเทพฯ
    const now = new Date();
    const options = { timeZone: 'Asia/Bangkok', hour12: false, hour: 'numeric', minute: 'numeric' };
    
    // ใช้ formatter เพื่อแยกชั่วโมงและนาที
    const formatter = new Intl.DateTimeFormat('en-US', options);
    const parts = formatter.formatToParts(now);
    
    let currentHour = 0;
    let currentMinute = 0;
    
    parts.forEach(part => {
        if (part.type === 'hour') currentHour = parseInt(part.value, 10);
        if (part.type === 'minute') currentMinute = parseInt(part.value, 10);
    });

    // ป้องกันบั๊กบางระบบที่คืนค่าเที่ยงคืนเป็น 24
    if (currentHour === 24) currentHour = 0;

    // 🟢 สูตรแก้ปัญหา Radio.co:
    // Radio.co มักจะ "โหลดไฟล์ล่วงหน้า" ประมาณ 2-5 นาทีก่อนถึงเวลาจริง
    // ถ้าเราเห็นว่านาที >= 55 เราจะบวกชั่วโมงเพิ่มไป 1 (เช่น 08:58 ให้ดึงไฟล์ของ 09:00)
    if (currentMinute >= 55) {
        currentHour = (currentHour + 1) % 24;
    }

    // แปลงตัวเลขให้เป็น 2 หลักเสมอ (เช่น 9 กลายเป็น "09")
    const formattedHour = currentHour.toString().padStart(2, '0');
    
    // 2. สร้างลิงก์ URL ปลายทาง
    const remoteUrl = `http://news.pakeefm.org/news${formattedHour}.mp3`;

    // 3. 🟢 ปิดการจำ Cache 100% (กัน Vercel และเซิร์ฟเวอร์จำลิงก์ชั่วโมงเก่า)
    res.setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate, s-maxage=0');
    res.setHeader('Pragma', 'no-cache');
    res.setHeader('Expires', '0');

    // 4. สั่ง Redirect
    res.redirect(302, remoteUrl);
}
