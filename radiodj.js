// ตัวแปรเก็บความจำชั่วคราว (จะอยู่ได้นานตราบใดที่เว็บยังดึงข้อมูลเรื่อยๆ)
let currentSong = "";
let lastUpdateTime = 0;

export default function handler(req, res) {
    // ป้องกันปัญหา CORS อนุญาตให้เว็บ VIBE24 ดึงข้อมูลได้
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');

    const { action, key, artist, title } = req.query;

    // 1. ส่วนรับข้อมูลจากโปรแกรม RadioDJ
    if (action === 'update') {
        if (key !== 'vibe24') return res.status(403).send('Invalid Key');

        // เช็คว่า RadioDJ ส่งข้อมูลมาถูกต้อง ไม่ใช่ตัวแปรดิบๆ
        if (artist && title && artist !== '$artist$') {
            currentSong = `${artist} - ${title}`;
            lastUpdateTime = Date.now();
            return res.status(200).send(`Success: ${currentSong}`);
        }
        return res.status(400).send('Invalid data from RadioDJ');
    }

    // 2. ส่วนส่งข้อมูลให้เว็บไซต์ VIBE24 นำไปใช้
    if (action === 'get') {
        // เช็คว่า RadioDJ อัปเดตล่าสุดเกิน 10 นาทีหรือยัง (ถ้าเกินถือว่าปิดรายการแล้ว)
        const isOnline = (Date.now() - lastUpdateTime) < 600000;

        return res.status(200).json({
            status: isOnline ? 'online' : 'offline',
            song: isOnline ? currentSong : ''
        });
    }

    return res.status(200).send('VIBE24 RadioDJ API is Running...');
}
